<?php
require_once(__DIR__.'/../libraries/api.php');
require_once(__DIR__.'/../vendor/autoload.php');
require_once(__DIR__.'/../libraries/date_helper.php');

$path = __DIR__.'/../data/config.json';
$jsonString = file_get_contents($path);
$jsonString = utf8_encode($jsonString);
$jsonData = json_decode($jsonString, true);

$client = new MongoDB\Client();
$databaseName = "myDatabase";
$collectionName = "myCollection";
$database = $client->selectDatabase($databaseName);
$collection = $database->selectCollection($collectionName);
$current_time = new DateTime();

$ip_to_check = $jsonData[0]['ip_address'];
$data1 = $collection->findOne(['_id' => $ip_to_check]);
$fetch_new = False;
if (! is_null($data1)) {
	$stored_time = new DateTime($data1['fetched']);
	echo "current time is ".$current_time->format('Y-m-d H:i:s').", stored time is ".$stored_time->format('Y-m-d H:i:s')."\n";
	$interval = $current_time->getTimestamp() - $stored_time->getTimestamp();
	echo "difference is ".$interval." seconds\n";
	if ($interval >= 600) {
		$fetch_new = True;
	} else {
		echo "use cache\n";
	}
} else {
	$fetch_new = True;
}

if ($fetch_new) {
	$document = [];
	$array_others=[];
	for($i=0; $i < count($jsonData); $i++){
		echo "fetch datas from {$jsonData[$i]['ip_address']}\n";
		$api = new Api($jsonData[$i]['ip_address'], $jsonData[$i]['username'], $jsonData[$i]['password']);
		$profit = json_decode($api->profit(), true);
		if (empty($profit)) {
			continue;
		}
		$profit['profit_open_coin'] = floatval($profit['profit_all_coin']) - floatval($profit['profit_closed_coin']);
		$profit['days'] = days_interval($current_time, $profit['first_trade_date']);
		$trade_count = $profit['closed_trade_count'];
		$win_trades = $profit['winning_trades'];
		$win_rate = ($trade_count > 0) ? round($win_trades * 100 / $trade_count, 2) : 0;
		$profit['win_rate'] = $win_rate;
		
		$trades = json_decode($api->trades($trade_count), true)['trades'];
		$balance = json_decode($api->balance(), true);

		$start_balance = $balance['starting_capital'];

		// Prepare profit chart data
		$total_profit = 0;
        $total_profit_abs = 0;
        $chart_profit_data = [];
        for($j = (count($trades) - 1); $j >= 0; $j--){
        	if (intval($trades[$j]['is_open']) == 1) {
        		continue;
        	}
            $close_profit = (($trades[$j]['close_profit_abs'] * 100 / $start_balance) + $total_profit);
            $total_profit = $close_profit;
            $close_profit = round($close_profit, 3);

            $close_profit_abs =  ($trades[$j]['close_profit_abs'] + $total_profit_abs);
            $total_profit_abs = $close_profit_abs;
            $closed_trades_asc[$i]['is_open'] = 0;

            $chart_profit_data[] = [
            	"close_profit"=> $close_profit,
            	"close_profit_abs"=>$close_profit_abs,
            	"close_date"=>$trades[$j]['close_date'],
            	"is_open"=>0
            ];
        }

		$status = json_decode($api->status(), true);

		if(count($status) > 0) {
            $open_trades_profit = 0;
            foreach ($status as $key => $value) {
                $open_trades_profit += ($value['profit_abs'] + $value['realized_profit']);
            }
            $date_now = new DateTime();
            $open_profit_pct = $total_profit + ($open_trades_profit * 100 / $start_balance);
            $open_profit_abs = $total_profit_abs + $open_trades_profit;
            $chart_profit_data[] = array('close_date'=>date_format($date_now,"Y-m-d H:i:s.u"), 'close_profit'=> $open_profit_pct, 'close_profit_abs'=> $open_profit_abs, 'is_open'=>1);
        }
		
		$config = json_decode($api->show_config(), true);
		$strategy_name = $config['strategy'];
		
		$document[] = [
			"_id"=> $jsonData[$i]['ip_address'],
			"strategy"=> $strategy_name,
			"strategy_version"=> $config['strategy_version'],
			"profit"=> $profit,
			"trades"=> $trades,
			"status"=> $status,
			"balance"=> $balance,
			"config"=> $config,
			"chart_profit_data"=> $chart_profit_data,
			"fetched"=> $current_time->format('Y-m-d H:i:s')
		];

		$dur_sec = duration_string_to_seconds($profit['avg_duration']);
		if($dur_sec > 0) {
			$array_others[] = [
				"strategy"=> $strategy_name,
				"duration"=> $profit['avg_duration'],
				"duration_sec"=> $dur_sec,
				"trade_count"=> $profit['closed_trade_count'],
				"winning_trades"=> $profit['winning_trades'],
				"losing_trades"=> ($profit['closed_trade_count'] - $profit['winning_trades']),
			];
		}
		
	}
	$document[] = [
		"_id"=> 'others',
		"data" => $array_others
	];
	$collection->drop();
	$operation_insert = $collection->insertMany($document);

	printf("Inserted %d document(s)\n", $operation_insert->getInsertedCount());
}