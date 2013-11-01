<?php
/**
* Утилита для логирования и исследования sql-запросов
* 
* @author Yuriy Panas (panas.net.ua)
*/
class dbLogger{

protected $log_file = '';               // путь к лог-файлу, лучше указывать полный
protected $log_file_rewrite = false;    // каждый раз перезаписывать лог-файл
protected $log_file_max_size = 1048676; // максимальный размер лог файла в байтах
protected $log_format = 'text';         // [text|csv]
// фильтрация записи в лог-файл
protected $log_filter = array(
        'min_req_time' => 0,            // [float|FAST|NORM|SLOW|TSLOW]
        'req_type'      => 'ALL',       // [ALL|READ|WRITE]
        'str_filter'    => '',          // фильтрация по строке. Строка регистронезависимая. Может быть массив строк
        'regex_filter'  => '');         // фильтрация по регулярному выражению
// фильтрация вывода на экран
protected $print_filter = array(
        'min_req_time' => 0,            // [float|FAST|NORM|SLOW|TSLOW]
        'req_type'      => 'ALL',       // [ALL|READ|WRITE]
        'str_filter'    => '',          // фильтрация по строке. Строка регистронезависимая. Может быть массив строк
        'regex_filter'  => '');         // фильтрация по регулярному выражению
protected $req_time_types = array(      // градация типов времени выполнения запросов
        'FAST'=>0,
        'NORM'=>0.01,
        'SLOW'=>0.5,
        'TSLOW'=>2);
protected $start_timer = 0;             
protected $req_time = 0;                // время выполнения текущего запроса в секундах
protected $sql = '';                    // текущий запрос
protected $arr_records = array();       // массив запросов за сеанс жизни объекта
protected $log_records = array();       // фильтрованный массив для записи в лог-файл
protected $print_records = array();     // фильтрованный массив для вывода на экран
protected $print = false;               // 
    
public function __construct($arr_settings=array()){
    // установка пути к лог файлу. По умолчанию ''. Если файл не указан - лог не пишется
    if(!empty($arr_settings['log_file'])) $this->log_file = $arr_settings['log_file'];
    // если true то лог всегда перезаписывается. По умолчанию false
    if(!empty($arr_settings['log_file_rewrite'])) $this->log_file_rewrite = $arr_settings['log_file_rewrite'];
    // максимальный размер лог файла в байтах. Если 0 - не ограничен. По умолчанию 1048676 - 1 МБ
    if(!empty($arr_settings['log_file_max_size'])) $this->log_file_max_size = $arr_settings['log_file_max_size'];
    // формат лог файла. По умолчанию text. Если csv то в качестве разделителя используется tab
    if(!empty($arr_settings['log_format']) && in_array($arr_settings['log_format'],array('text','csv'))) $this->log_format = $arr_settings['log_format'];
    // Фильтрация для лог файла
    // только запросы с временем выполнения >= заданного. Может быть число либо константа [NORM|SLOW|TSLOW]. По умолчанию 0.
    if(!empty($arr_settings['log_filter']['min_req_time'])) $this->log_filter['min_req_time'] = $arr_settings['log_filter']['min_req_time'];
    // только определенный тип запроса READ или WRITE. По умолчанию все
    if(!empty($arr_settings['log_filter']['req_type']) && ($arr_settings['log_filter']['req_type'] == 'READ' || $arr_settings['log_filter']['req_type'] == 'WRITE')) 
        $this->log_filter['req_type'] = $arr_settings['log_filter']['req_type'];
    // только запросы содержащие указанную строку либо массив строк. Строки регистронезависимые. По умолчанию ''
    if(!empty($arr_settings['log_filter']['str_filter'])) $this->log_filter['str_filter'] = $arr_settings['log_filter']['str_filter'];
    // только запросы соответствующие заданному регулярному выражению. По умолчанию ''
    if(!empty($arr_settings['log_filter']['regex_filter'])) $this->log_filter['regex_filter'] = $arr_settings['log_filter']['regex_filter'];
    // Фильтрация для вывода на экран
    // только запросы с временем выполнения >= заданного. Может быть число либо константа [NORM|SLOW|TSLOW]. По умолчанию 0.
    if(!empty($arr_settings['print_filter']['min_req_time'])) $this->print_filter['min_req_time'] = $arr_settings['print_filter']['min_req_time'];
    // только определенный тип запроса READ или WRITE. По умолчанию все
    if(!empty($arr_settings['print_filter']['req_type']) && ($arr_settings['print_filter']['req_type'] == 'READ' || $arr_settings['print_filter']['req_type'] == 'WRITE')) 
        $this->print_filter['req_type'] = $arr_settings['print_filter']['req_type'];
    // только запросы содержащие указанную строку либо массив строк. Строки регистронезависимые. По умолчанию ''
    if(!empty($arr_settings['print_filter']['str_filter'])) $this->print_filter['str_filter'] = $arr_settings['print_filter']['str_filter'];
    // только запросы соответствующие заданному регулярному выражению. По умолчанию ''
    if(!empty($arr_settings['print_filter']['regex_filter'])) $this->print_filter['regex_filter'] = $arr_settings['print_filter']['regex_filter'];
    
    // задание значений для констант [NORM|SLOW|TSLOW]
    if(!empty($arr_settings['TSLOW']) && is_numeric($arr_settings['TSLOW']) && $arr_settings['TSLOW'] > $this->req_time_types['SLOW']) $this->req_time_types['TSLOW'] = $arr_settings['TSLOW'];
    if(!empty($arr_settings['SLOW']) && is_numeric($arr_settings['SLOW']) && $this->req_time_types['NORM'] < $arr_settings['SLOW'] 
            && $arr_settings['SLOW'] < $this->req_time_types['TSLOW']) $this->req_time_types['SLOW'] = $arr_settings['SLOW'];
    if(!empty($arr_settings['NORM']) && is_numeric($arr_settings['NORM']) && $this->req_time_types['FAST'] < $arr_settings['NORM']
            && $arr_settings['NORM'] < $this->req_time_types['SLOW']) $this->req_time_types['NORM'] = $arr_settings['NORM'];
    
}

/**
* фиксируем стартовое время
*/
public function start_timer(){
    $this->start_timer = microtime(true);
}

/**
* сохраняем текущий SQL-запрос
* 
* @param string $sql SQL-запрос
*/
public function get_sql($sql){
    $this->sql = trim($sql);
}

/**
* фиксируем время окончания запроса и сохраняем результат
*/
public function end_timer(){
    if($this->start_timer){
        $this->req_time = round( microtime(true) - $this->start_timer, 5);
        $this->start_timer = 0;
        $this->handler(); 
    }
}

/**
* обработчик принятых SQL-запросов
*/
protected function handler(){
    $req_type = 'READ';
    if( substr( strtoupper($this->sql),0,6) != 'SELECT') $req_type = 'WRITE';
    $req_time_type = 'FAST';
    if($this->req_time > $this->req_time_types['TSLOW'] ){
        $req_time_type = 'TSLOW';
    }elseif($this->req_time > $this->req_time_types['SLOW'] ){
        $req_time_type = 'SLOW';
    }elseif($this->req_time > $this->req_time_types['NORM'] ){
        $req_time_type = 'NORM';
    }
    
    $this->arr_records[] = array(
            'time' => date('Y-m-d H:i:s'), 
            'ip' => $_SERVER['REMOTE_ADDR'],
            'req_time_type' => $req_time_type,
            'req_time' => $this->req_time, 
            'req_type' => $req_type, 
            'sql' => $this->sql);
    $this->req_time = 0;
    $this->sql = '';
}

/**
* фильтрация по времени выполнения запроса.
* 
* @param array $record запрос
* @param mixed $min_req_time фильтр - число либо текстовая константа [NORM|SLOW|TSLOW]
*/
protected function filter_req_time_type($record, $min_req_time=0){
    $delete = false;
    if($min_req_time){
        if(is_numeric($min_req_time)){
            if( $record['req_time'] <= $min_req_time ) $delete = true;
        }else{
            switch( $min_req_time){
                case 'FAST':
                    break;
                case 'NORM':
                    if( $this->req_time_types['NORM'] >= $record['req_time']) $delete = true;
                    break;
                case 'SLOW':
                    if( $this->req_time_types['SLOW'] >= $record['req_time']) $delete = true;
                    break;
                case 'TSLOW':
                    if( $this->req_time_types['TSLOW'] >= $record['req_time']) $delete = true;
                    break;
                default:
                    break;
            }
        }
    }
    return $delete;
}
/**
* фильтрация по типу запроса READ или WRITE
* 
* @param array $record запрос
* @param string $type_req фильтр
*/
protected function filter_type_req($record, $type_req = ''){
    $delete = false;
    switch( $type_req ){
        case 'ALL':
            break;
        case 'READ':
            if( 'READ' != $record['req_type']) $delete = true;
            break;
        case 'WRITE':
            if( 'WRITE' != $record['req_type']) $delete = true;
            break;
        default:
            break;
    }
    return $delete;
}

/**
* Филтрация по строке
* 
* @param array $record запись
* @param mixed $str_filter строка, по которой фильтровать
*/
protected function filter_string($record, $str_filter=''){
    $delete = false;
    if( $str_filter ){
        $delete = true;
        if(is_array( $str_filter ) ){
            foreach( $str_filter AS $str) if( strpos(strtoupper($record['sql']), strtoupper($str)) !== false) $delete = false;
        }elseif(is_string($str_filter)){
            if( strpos(strtoupper($record['sql']), strtoupper($str_filter)) !== false) $delete = false;
        }
    } 
    return $delete;
}

/**
* Фильтрация по регулярному выражению
* 
* @param array $record запись
* @param string $regex_filter регулярное выражение, по которому фильтравать
*/
protected function filter_regex($record, $regex_filter=''){
    $delete = false;
    if( $regex_filter && !preg_match($regex_filter, $record['sql'])) $delete = true;
    return $delete;
}

/**
* Фильтры, которые применяются на записи, которые будут записаны в лог-файл
*/
protected function log_filter(){
    if(count($this->arr_records) > 0 && $this->log_file){
        $this->log_records = $this->arr_records; // сначала добавляем все записи
        // а теперь фильтруем
        foreach($this->log_records AS $key => $record){
            // фильтрация по времени запроса
            if($this->filter_req_time_type($record, $this->log_filter['min_req_time'])) unset( $this->log_records[$key]);
            // фильтрация по типу запроса
            if($this->filter_type_req($record, $this->log_filter['req_type'])) unset( $this->log_records[$key]);   
            // фильтрация по строке
            if($this->filter_string($record, $this->log_filter['str_filter'])) unset( $this->log_records[$key]);
            // фильтрация по регулярному выражению
            if($this->filter_regex($record, $this->log_filter['regex_filter'])) unset( $this->log_records[$key]);
        }
    }
}

/**
* Фильтры, которые применяются на записи, которые будут выведены на экран
*/
protected function print_filter(){
    if(count($this->arr_records) > 0){
        $this->print_records = $this->arr_records; // сначала добавляем все записи
        // а теперь фильтруем
        foreach($this->print_records AS $key => $record){
            // фильтрация по времени запроса
            if($this->filter_req_time_type($record, $this->print_filter['min_req_time'])) unset( $this->print_records[$key]);
            // фильтрация по типу запроса
            if($this->filter_type_req($record, $this->print_filter['req_type'])) unset( $this->print_records[$key]);
            // фильтрация по строке
            if($this->filter_string($record, $this->print_filter['str_filter'])) unset( $this->print_records[$key]); 
            // фильтрация по регулярному выражению
            if($this->filter_regex($record, $this->print_filter['regex_filter'])) unset( $this->print_records[$key]);  
        }
    }
}

/**
* Запись в лог-файл
*/
protected function save_to_logfile(){
    if($this->log_file){         
        $this->log_filter();
        
        if(count($this->log_records) > 0){
            if(file_exists($this->log_file) && !$this->log_file_rewrite && ( $this->log_file_max_size == 0 || filesize($this->log_file) < $this->log_file_max_size)){
                $f_log = @fopen($this->log_file, 'ab');
            }else{
                if(is_writable( pathinfo($this->log_file, PATHINFO_DIRNAME) )){
                    $f_log = @fopen($this->log_file, 'wb');
                }else{
                    //echo 'Folder '.pathinfo($this->log_file, PATHINFO_DIRNAME).' is not writable!';
                }
            }
            if($f_log){
                if($this->log_format == 'text'){
                    foreach($this->log_records AS $record){
                        fwrite($f_log, $record['time'].' '.$record['ip'].' '.$record['req_time_type'].' req_time:'.$record['req_time'].', sql: '.$record['sql']."\n");
                    }
                }elseif($this->log_format == 'csv'){
                    foreach($this->log_records AS $record){
                        fwrite($f_log, $record['time']."\t".$record['ip']."\t".$record['req_time_type']."\t".$record['req_time']."\t".str_replace(array("\t","\r","\n"),'',$record['sql'])."\n");
                    }
                }
                fclose($f_log);
            }
        }
    }
}

/**
* вызов функции вывода на экран
* 
* @param mixed $continue_to_show - 2 - сохранить в сессию и выводить на экран при каждом переходе, 1 - показать один раз, 0 - отключить постоянный показ
*/
public function debug_print($continue_to_show){
    if(session_id()){
        if(!empty($continue_to_show) && ($continue_to_show == 2 || $continue_to_show == 'on')){
            $_SESSION['dblogger_continue_to_show'] = true;
            $this->print = true;
        }elseif(isset($continue_to_show) && ( $continue_to_show === 0 || $continue_to_show === '0' || $continue_to_show == 'off')){
            if(!empty($_SESSION['dblogger_continue_to_show'])) unset($_SESSION['dblogger_continue_to_show']);
            $this->print = false;
        }else{
            $this->print = true;
        }
    }else{
        $this->print = true;
    }  
}

/**
* вывод лога на экран
*/
protected function print_to_screen(){
    
    if(count($this->print_records) > 0){   
        $arr_summary = array(
                'queries'=>count($this->arr_records),
                'read'=>0,
                'write'=>0,
                'fast'=>0,
                'norm'=>0,
                'slow'=>0,
                'tslow'=>0,
                'total_time'=>0,
                'average_time'=>0,
                'max_time'=>0,
                'min_time'=>1);
        foreach($this->arr_records AS $record){
            if($record['req_type'] == 'READ') $arr_summary['read']++;
            if($record['req_type'] == 'WRITE') $arr_summary['write']++;
            if($record['req_time_type'] == 'FAST') $arr_summary['fast']++;
            if($record['req_time_type'] == 'NORM') $arr_summary['norm']++;
            if($record['req_time_type'] == 'SLOW') $arr_summary['slow']++;
            if($record['req_time_type'] == 'TSLOW') $arr_summary['tslow']++;
            $arr_summary['total_time'] += $record['req_time'];
            if($record['req_time'] > $arr_summary['max_time']) $arr_summary['max_time'] = $record['req_time'];
            if($record['req_time'] < $arr_summary['min_time']) $arr_summary['min_time'] = $record['req_time'];
        }
        $arr_summary['average_time'] = round($arr_summary['total_time']/$arr_summary['queries'], 5);
?>        
        <style>
            .dbl_table {border:1px solid #CCC;border-collapse:collapse;margin-bottom: 5px;}
            .dbl_table th {border:1px solid #CCC;text-align: center;background-color: #EEE;font-family: Verdana, sans-serif;font-size: 10px;color:#444;}
            .dbl_table td {border:1px solid #CCC;font-family: Verdana, sans-serif;font-size: 11px;color:#333;}
            .dbl_green {color:green !important;}
            .dbl_blue {color:blue !important;}
            .dbl_orange {color:orange !important;}
            .dbl_red {color:red !important;}
        </style>
        <div style="margin:10px;">
        <p>Суммарная информация по всем запросам</p>
        <table class="dbl_table">
        <tr>
            <th>Запросов всего</th>
            <th>Чтение</th>
            <th>Запись</th>
            <th>Быстрых</th>
            <th>Нормальных</th>
            <th>Медленных</th>
            <th>Очень медленных</th>
            <th>Суммарное время</th>
            <th>Макс. время</th>
            <th>Среднее время</th>
            <th>Мин. время</th>
        </tr>
        <tr>
            <td align="center"><?=$arr_summary['queries']?></td>
            <td align="center"><?=$arr_summary['read']?></td>
            <td align="center"><?=$arr_summary['write']?></td>
            <td align="center" class="dbl_green"><?=$arr_summary['fast']?></td>
            <td align="center" class="dbl_blue"><?=$arr_summary['norm']?></td>
            <td align="center" class="dbl_orange"><?=$arr_summary['slow']?></td>
            <td align="center" class="dbl_red"><?=$arr_summary['tslow']?></td>
            <td><?=$arr_summary['total_time']?></td>
            <td><?=$arr_summary['max_time']?></td>
            <td><?=$arr_summary['average_time']?></td>
            <td><?=$arr_summary['min_time']?></td>
        </tr>
        </table>
        <p>Фильтры: 
                <?php if($this->print_filter['min_req_time']) echo 'мин. время: <b>'.$this->print_filter['min_req_time'].'</b>';?>
                <?php if($this->print_filter['req_type'] && $this->print_filter['req_type'] != 'ALL') echo ' тип запроса: <b>'.$this->print_filter['req_type'].'</b>';?>
                <?php if($this->print_filter['str_filter']) echo ' фильтр по строке: <b>'.$this->p($this->print_filter['str_filter']).'</b>';?>
                <?php if($this->print_filter['regex_filter']) echo ' фильтр по рег. выражению: <b>'.$this->print_filter['regex_filter'].'</b>';?></p>
        <table class="dbl_table">
        <tr>
            <th>№</th>
            <th>Дата</th>
            <th>IP</th>
            <th>Время</th>
            <th>Время</th>
            <th>Запрос</th>
        </tr>
<?php
        $i=1;
        foreach($this->print_records AS $record){
            echo '<tr>
                <td>'.$i.'</td>
                <td>'.$record['time'].'</td>
                <td>'.$record['ip'].'</td>
                <td align="center" '.$this->style_req_time_type($record['req_time_type']).'>'.$record['req_time_type'].'</td>
                <td '.$this->style_req_time_type($record['req_time_type']).'>'.$record['req_time'].'</td>
                <td>'.$this->style_sql_query($record['sql']).'</td>
                </tr>';
            $i++;
        }
        echo '</table></div>';
    }
}


public function __destruct(){
    
    if($this->print || !empty($_SESSION['dblogger_continue_to_show'])){
        $this->print_filter();
        $this->print_to_screen();
    }

    $this->save_to_logfile();
}

/**
* цветовое оформление типов скорости выполнения запроса
* 
* @param string $req_time_type
* @return mixed
*/
protected function style_req_time_type($req_time_type=''){
    switch($req_time_type){
        case 'FAST':
            return 'class="dbl_green"';
            break;
        case 'NORM':
            return 'class="dbl_blue"';
            break;
        case 'SLOW':
            return 'class="dbl_orange"';
            break;
        case 'TSLOW':
            return 'class="dbl_red"';
            break;
    }
}

/**
* цветовое оформление констант SQL-запросов
* 
* @param string $sql запрос
*/
protected function style_sql_query($sql=''){
    return preg_replace('/(SELECT|select|from|FROM|WHERE|INSERT|DELAYED|INTO|UPDATE|LIMIT|ORDER BY|GROUP BY|DISCTINCT|VALUES|JOIN|LEFT JOIN|AND|OR|ON|DESC|ASC)/',"<span class=\"dbl_blue\">$0</span>",$sql);
}

protected function p($input){
    if(is_array($input)){
        return print_r($input, true);
    }else{
        return $input;
    }
}

}