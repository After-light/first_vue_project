<?php

$created_at = strtotime('-24 hours');

$dsn = 'mysql:host=123.140.238.58;dbname=dice';
$user = 'ethgame';
$password = 'ahfmsek111';

$pdo_fck = new PDO($dsn, $user, $password);

$sql = "
    select
        count(*) as all_tx_count,
        sum(case when time > {$created_at} then 1 else 0 end) as tx_count,
        sum(case when time > {$created_at} then bet else 0 end) as eth_sum
    from t_history
";

$sth = $pdo_fck->prepare($sql);
$sth->execute();

$row = $sth->fetch(PDO::FETCH_ASSOC);
$all_tx_count = $row['all_tx_count'];
$tx_count = $row['tx_count'];
$eth_sum = $row['eth_sum'];

$sql = "select sum(bet) from t_history";

$sth = $pdo_fck->prepare($sql);
$sth->execute();
$all_eth_sum = $sth->fetchColumn();

$sql = "
    select
        gambler as address, sum(wins) as wins, sum(bet) as bet, sum(wins) - sum(bet) as profit
    from t_history
    where time > {$created_at}
    group by gambler
    order by bet desc
    limit 3
";

$sth = $pdo_fck->prepare($sql);
$sth->execute();
$top_bet = $sth->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    select
        h.gambler as address, sum(h.wins) as wins, sum(h.bet) as bet,
        sum((case when j.amount is null then 0 else j.amount end)/pow(10, 18)) + sum(h.wins) - sum(h.bet) as profit
    from t_history as h
    left join t_jackpot as j on h.settle_tx = j.tx_hash
    where h.time > {$created_at}
    group by gambler
    order by profit desc
    limit 10;
";

$sth = $pdo_fck->prepare($sql);
$sth->execute();
$top_profit = $sth->fetchAll(PDO::FETCH_ASSOC);

$records = array();
$max = array();

$id = 0;
$size = 1000;

for ($i = 0; true; $i++) {
    $finished = false;

    $offset = $i * 1000;

    $sql = "
        select id, modulo, gambler, wins, time
        from t_history
    ";

    if ($id > 0) {
        $sql .= "
            where id < {$id}
        ";
    }

    $sql .= "
        order by id desc
        limit {$offset}, {$size}
    ";

    $sth = $pdo_fck->prepare($sql);
    $sth->execute();
    $values = $sth->fetchAll(PDO::FETCH_ASSOC);

    foreach ($values as $v) {
        $id = $v['id'];

        if ($v['time'] < $created_at) {
            $finished = true;
            break;
        }

        if ($v['wins'] == 0) {
            $records[$v['modulo']][$v['gambler']] = 0;
            continue;
        }

        if (! isset($records[$v['modulo']][$v['gambler']])) {
            $records[$v['modulo']][$v['gambler']] = 0;
        }

        $records[$v['modulo']][$v['gambler']]++;

        if (empty($max[$v['modulo']]) || $max[$v['modulo']]['count'] < $records[$v['modulo']][$v['gambler']]) {
            $max[$v['modulo']] = array(
                'address' => $v['gambler'],
                'count' => $records[$v['modulo']][$v['gambler']],
            );
        }
    }

    if ($finished) {
        break;
    }
}

uasort($max, function($a, $b) {
    return $a['count'] < $b['count'];
});

$top_win = array();

foreach ($max as $game => $v) {
    $top_win[] = array('game' => $game) + $v;
}


$data = json_encode(array(
    'timestamp' => $created_at,
    'all_tx_count' => $all_tx_count,
    'tx_count' => $tx_count,
    'all_eth_sum' => $all_eth_sum,
    'eth_sum' => $eth_sum,
    'top_bet' => $top_bet,
    'top_profit' => $top_profit,
    'top_win' => $top_win,
));

if (isset($_GET['callback'])) {
    echo $_GET['callback'], "(", $data, ");";
} else {
    echo $data;
}
