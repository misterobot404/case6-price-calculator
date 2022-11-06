<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\ObjectOfPool;
use App\Models\Pool;
use App\Models\TypeOfNumberRooms;
use App\Models\TypeOfSegment;
use App\Models\TypeOfWall;
use App\Models\TypeOfCondition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use PhpOffice\PhpSpreadsheet\IOFactory;

class CalculationController extends Controller
{
    public function getCalculationStatus()
    {
        // Формируем окончательный ответ в нужном формате
        return response()->json([
            "message" => null,
            "data" => [
                "calculation_status" => Group::where('Пользователь', auth()->id())->where('Статус', null)->exists()
            ]
        ]);
    }

    public function getReferenceBooks()
    {
        return response()->json([
            "message" => null,
            "data" => [
                "type_of_condition" => TypeOfCondition::all(),
                "type_of_number_rooms" => TypeOfNumberRooms::all(),
                "type_of_segment" => TypeOfSegment::all(),
                "type_of_wall" => TypeOfWall::all()
            ]
        ]);
    }

    public function getPools()
    {
        // Текущая расчитываемая группа
        $group = Group::where('Пользователь', auth()->id())->where('Статус', null)->first();

        // Пулы для этой группы
        return response()->json([
            "message" => null,
            "data" => [
                "pools" => Pool::where('Группа', $group->id)->get()
            ]
        ]);
    }

    public function getObjects($id)
    {
        // Объекты для этого пула
        return response()->json([
            "message" => null,
            "data" => [
                "objects" => ObjectOfPool::where('Пул', $id)->get()
            ]
        ]);
    }

    public function getObjectAndAnalogs($pool_id, $object_id)
    {
        $object = ObjectOfPool::find($object_id)->first();

        $analogs = (DB::select('SELECT * from НайтиАналоги('.$object->КоличествоКомнат.','.$object->Сегмент.','.$object->ЭтажностьДома.','.$object->МатериалСтен.','.$object->ЭтажРасположения.','.$object->ПлощадьКвартиры.','.$object->ПлощадьКухни.','.$object->НаличиеБалконаЛоджии.','.$object->МетроМин.','.$object->Состояние.')'));

        // Объекты для этого пула
        return response()->json([
            "message" => null,
            "data" => [
                "object" => $object,
                "analogs" => $analogs
            ]
        ]);
    }

    public function getAllCalculationObjects()
    {
        $objects = DB::table('ОцениваемаяНедвижимость')
            ->leftJoin('Пул', 'ОцениваемаяНедвижимость.Пул', '=', 'Пул.id')
            ->leftJoin('Группы', 'Группы.id', '=', 'Пул.Группа')
            ->select('ОцениваемаяНедвижимость.*', 'Группы.Статус')
            ->where('Статус', null)
            ->get();

        // Объекты для этого пула
        return response()->json([
            "message" => null,
            "data" => [
                "objects" => $objects
            ]
        ]);
    }

    public function breakCalculation()
    {
        $group = Group::find(request('group_id'))->delete();

        return response()->json([
            "message" => null,
            "data" => null
        ], 204);
    }

    /* Входные данные Excel.
    Ключ ячейки - значение:
    0 => Адрес
    1 => Количество комнат
    2 => Сегмент (Новостройка, современное жилье, старый жилой фонд)
    3 => Этажность дома
    4 => Материал стен (Кирпич, панель, монолит)
    5 => Этаж расположения
    6 => Площадь квартиры, кв.м
    7 => Площадь кухни, кв.м
    8 => Наличие балкона/лоджии
    9 => Удаленность от станции метро, мин. пешком
    10 => Состояние (без отделки, муниципальный ремонт, с современная отделка)
    */
    public function parseFileOfObjects()
    {
        // Парсим excel для получения объектов для оценки
        $spreadsheet = IOFactory::load(request('file_of_objects'));
        $sheet = $spreadsheet->getActiveSheet();
        $objects = $sheet->toArray();
        array_shift($objects);

        // В таблицу "Группы" добавляется новая строка, передаём только id пользователя,
        // Статус: NULL - новая, 1 - посчитано архив, 2 - не посчитано архив.
        // Получаем обратно id группы
        $group = Group::create([
            'Пользователь' => auth()->id()
        ]);

        // В таблицу "Пул" надо вставить строки для тех пулов которые есть в файле
        // Параметры: id группы, id из таблицы ТипКоличестваКомнат
        $created_pools = [];
        // Перебираем все объекты недвижимости. Добавляем их в таблицу недвижимости и создаём для них пул по необходимости
        $result_objects_db = [];
        foreach ($objects as $object) {
            // Проверяем существует ли такой пул, если пул не был создан, то создаём новый
            if (!in_array($object['1'], array_column($created_pools, "КоличествоКомнат"), true)) {
                // Получаем id для этого типа количества комнат
                TypeOfNumberRooms::where('Название', $object['1'])->first();

                // Создаём новый пул в базе
                $pool = Pool::create([
                    'Группа' => $group->id,
                    'КоличествоКомнат' => TypeOfNumberRooms::where('Название', $object['1'])->first()->id,
                    "КоличествоОбъектов" => 0
                ]);

                // Сохраняем добавленный пул в массив для уменьшения количества запросов к базе
                $created_pools[] = [
                    "id" => $pool->id,
                    "КоличествоКомнат" => $object['1'],
                    "КоличествоОбъектов" => 0,
                ];
            }

            // Получаем пул в который включена эта квартира
            $using_pool = null;
            foreach ($created_pools as $index => $pool) {
                if ($pool['КоличествоКомнат'] === $object['1']) {
                    ++$created_pools[$index]["КоличествоОбъектов"];
                    $using_pool = $pool;
                    break;
                }
            }

            // В таблицу "ОцениваемаяНедвижимость" добавляем данные из файла.
            // Передаём id пула, соответствующего конкретной записи, и параметры недвижимости.
            // Надо вставлять ключи из ТипКоличестваКомнат, ТипСегмента, ТипМатериалаСтен, ТипНаличияБалконаЛоджии, ТипСостояния.
            $result_objects_db[] = ObjectOfPool::create([
                'Пул' => $using_pool['id'],
                'Местоположение' => $object['0'],
                'КоличествоКомнат' => TypeOfNumberRooms::where('Название', $using_pool['КоличествоКомнат'])->first()->id,
                'Сегмент' => TypeOfSegment::where('Название', $object['2'])->first()->id,
                'ЭтажностьДома' => $object['3'],
                'МатериалСтен' => TypeOfWall::where('Название', $object['4'])->first()->id,
                'ЭтажРасположения' => $object['5'],
                'ПлощадьКвартиры' => $object['6'],
                'ПлощадьКухни' => $object['7'],
                'НаличиеБалконаЛоджии' => $object['8'] === "Да" ? 1 : 0,
                'МетроМин' => $object['9'],
                'Состояние' => TypeOfCondition::where('Название', $object['10'])->first()->id
            ]);
        }

        // Устанавливаем поле КоличествоОбъектов для пулов
        foreach ($created_pools as $pool) {
            Pool::where('id', $pool['id'])->update(['КоличествоОбъектов' => $pool["КоличествоОбъектов"]]);
        }

        // Формируем окончательный ответ в нужном формате
        // Необходимо вернуть список созданных объектов для дальнейшего обогащения
        return response()->json([
            "message" => "Данные успешно загружены. Необходимо добавить координаты",
            "data" => [
                "objects" => $result_objects_db
            ]
        ]);
    }

    public function updateObjectCoords()
    {
        $objects = request('objects');
        // Устанавливаем поле КоличествоОбъектов для пулов
        foreach ($objects as $el) {
            $object = ObjectOfPool::find($el["id"]);
            $object->coordx = $el["coordy"];
            $object->coordy = $el["coordx"];
            $object->save();
        }

        // Формируем окончательный ответ в нужном формате
        // Необходимо вернуть список созданных объектов для дальнейшего обогащения
        return response()->json([], 204);
    }
}
