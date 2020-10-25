<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Models\Rate;
use SimpleXMLElement;

class GetExchangeRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:rates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the value of the exchange rates for today';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        // Задаем массив с интересующими валютами
        $currencies = [
            'USD',
            'EUR',
        ];

        // Получаем сегодняшнюю дату для сравнения с меткой today в Redis
        $today = date("d.m.Y");

        // Если метка today существует и равна сегодняшей дате
        if (Redis::exists('today') && (Redis::get('today') == $today)) {

            // Считываем информацию о курсах из Redis и выводим на экран
            foreach ($currencies as $currency) {

                $rates[$currency] = Redis::get($currency);

                echo "$currency: " . Redis::get($currency) . "\n";
            }
        } else { // Если в Redis информация устарела или отсутствует

            // Обращаемся к БД
            $rates_coll = Rate::select('name', 'rate')
                ->where('date', $today)
                ->get();

            if ($rates_coll->isNotEmpty()) { // Если в БД уже есть записи на сегодня, но в Redis их по какой-то причине нет

                // Обновляем информацию в Redis и выводим на экран
                Redis::flushDB();
                Redis::set('today', $today);

                foreach ($rates_coll as $rate_from_db) {

                    Redis::set($rate_from_db->name, $rate_from_db->rate);

                    echo "$rate_from_db->name: $rate_from_db->rate \n";
                }
            } else { // Если в базе данных тоже нет информации о сегодняшнем курсе валют

                // Инициализируем сеанс cURL
                $ch = curl_init();

                // Настраиваем соединение
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_URL, "http://www.cbr.ru/scripts/XML_daily.asp");

                // записываем XML-ответ в ХML объект}
                $cbr_xml_resp = new SimpleXMLElement(curl_exec($ch));

                // Закрываем сеанс
                curl_close($ch);

                // Достаем данные о курсе для заданных валют
                foreach ($currencies as $currency) {

                    // Итерируем набор блоков 
                    foreach ($cbr_xml_resp->Valute as $valute) {

                        // В случае совпадения буквенного кода с заданными валютами
                        if ($valute->CharCode == $currency) {
                            // Заполняем массив для последущей записи курсов валют в БД
                            $rates_to_insert[] = ['date' => $today, 'name' => $currency, 'rate' => (string)$valute->Value[0]];
                        }
                    }
                }

                // Очищаем таблицу от прошлых значений
                DB::table('rates')->truncate();

                // Сохраняем новые значения в базе
                if (Rate::insert($rates_to_insert)) {
                    echo "\n" . "Информация в базе данных обновлена" . "\n";
                }

                // И сохраняем данные в Redis и выводим на экран
                Redis::flushDB();
                Redis::set('today', $today);

                foreach ($rates_to_insert as $rate_to_insert) {

                    Redis::set($rate_to_insert['name'], $rate_to_insert['rate']);

                    echo $rate_to_insert['name'] . ": " . $rate_to_insert['rate'] . "\n";
                }
            }
        }

        return;
    }
}
