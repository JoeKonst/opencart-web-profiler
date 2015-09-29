<?php
class WebProfiler {
    protected $config;
    protected $db;
    protected $request;
    protected $session;
    protected $start_time;
    protected $finish_time;
    protected $start_memory;
    protected $finish_memory;
    protected $entries = array();
    protected $response_code;
    protected $main_controller;
    protected $main_method;

    public function __construct() {
        $this->setStartTime(microtime(true));
        $this->setStartMemory(memory_get_usage(true));
    }

    public function finish() {
        $this->setFinishTime(microtime(true));
        $this->setFinishMemory(memory_get_usage(true));
        $this->setResponseCode(http_response_code());

        $front_entries = $this->getEntries('method');
        $fronts = array_slice($front_entries['method']['entries'], 0, 1);
        $main_front = array_shift($fronts);
        $main_front = explode('::', $main_front['text']);

        $this->setMainController($main_front[0]);
        $this->setMainMethod($main_front[1]);
    }

    public function formatTime($time) {
        return round($time, 4);
    }

    public function formatMemory($memory) {
        $unit = array('B', 'KB', 'MB', 'GB');

        if ($memory) {
            return round(($memory) / pow(1024, ($i = floor(log(($memory), 1024)))), 2) . ' ' . $unit[$i];
        } else {
            return '0 B';
        }
    }

    public function log() {
        if (isset($this->request->cookie['wplog']) && $this->request->cookie['wplog'] == 1) {
            $log = new Log('web_profiler.txt');
            $log->write('START: ' . $this->request->server['REQUEST_URI'] . ' Response: ' . $this->getResponseCode() . ' Action: ' . $this->getMainController() . '::' . $this->getMainMethod());

            $entry_groups = $this->getEntries('', 500);

            foreach ($entry_groups as $entry_group) {
                foreach ($entry_group['entries'] as $entry) {
                    $text  = $entry['time_taken'] . ' seconds ';
                    $text .= $entry['type'] . ' ';
                    $text .= $entry['text'];

                    $log->write(print_r($text, true));
                }
            }

            $log->write(print_r('Memory Used: ' . $this->formatMemory($this->getFinishMemory() - $this->getStartMemory()), true));
            $log->write('FINISH');
        }
    }

    public function template() {
        if (isset($this->request->cookie['wptemplate']) && $this->request->cookie['wptemplate'] == 0) {
            return '';
        }

        $template = new Template();

        $template->data['response_code'] = $this->getResponseCode();
        $template->data['memory_used'] = $this->formatMemory($this->getFinishMemory() - $this->getStartMemory());
        $template->data['time_taken'] = $this->formatTime($this->getFinishTime() - $this->getStartTime());
        $template->data['controller'] = $this->getMainController();
        $template->data['method'] = $this->getMainMethod();

        $method = array();
        $entries = $this->getEntries('method');
        if (isset($entries['method']) && !empty($entries['method'])) {
            $method = $entries['method'];
        }

        $template->data['type_method'] = $method;

        $query = array();
        $entries = $this->getEntries('query');
        if (isset($entries['query']) && !empty($entries['query'])) {
            $query = $entries['query'];
        }

        $template->data['type_query'] = $query;

        $template_data = array();
        $entries = $this->getEntries('template');
        if (isset($entries['template']) && !empty($entries['template'])) {
            $template_data = $entries['template'];
        }

        $template->data['type_template'] = $template_data;

        $template->data['vqmod_logs'] = glob(DIR_SYSTEM . '../vqmod/logs/*.log');

        $system_logs = glob(DIR_SYSTEM . 'logs/*');

        $exclude_logs = array(
            'index.html'
        );

        $template->data['system_logs'] = array();
        foreach ($system_logs as $system_log) {
            if (!in_array(basename($system_log), $exclude_logs) && filesize($system_log)) {
                $template->data['system_logs'][] = array(
                    'name' => basename($system_log),
                    'size' => $this->formatMemory(filesize($system_log)),
                );
            }
        }

        return $template->fetch('default/template/common/web_profiler.tpl');
    }

    public function addEntry($type, $text, $start) {
		$this->entries[] = array(
			'type'       => $type,
            'text'       => trim(preg_replace('/\s+/', ' ', $text)),
			'time_taken' => $this->formatTime(microtime(true) - $start),
		);
    }

	public function getEntries($entry_type = '', $limit = 10) {
        $type = $text = $time_taken = $sorted_entries = $count = array();

        $all_entries = $this->entries;

        foreach ($this->entries as $key => $row) {
            $type[$key]       = $row['type'];
            $time_taken[$key] = $row['time_taken'];
        }

        array_multisort($type, SORT_ASC, $time_taken, SORT_DESC, $all_entries);

        foreach ($all_entries as $all_entry) {
            if (!isset($sorted_entries[$all_entry['type']]['quantity_total'])) {
                $sorted_entries[$all_entry['type']]['quantity_total'] = 0;
            }

            if (!isset($sorted_entries[$all_entry['type']]['quantity_entries'])) {
                $sorted_entries[$all_entry['type']]['quantity_entries'] = 0;
            }

            if (!isset($sorted_entries[$all_entry['type']]['time_taken_total'])) {
                $sorted_entries[$all_entry['type']]['time_taken_total'] = 0;
            }

            if (!isset($sorted_entries[$all_entry['type']]['time_taken_entries'])) {
                $sorted_entries[$all_entry['type']]['time_taken_entries'] = 0;
            }

            $sorted_entries[$all_entry['type']]['quantity_total']++;

            $sorted_entries[$all_entry['type']]['time_taken_total'] += $all_entry['time_taken'];

            if ($sorted_entries[$all_entry['type']]['quantity_entries'] < $limit) {
                $sorted_entries[$all_entry['type']]['entries'][] = $all_entry;

                $sorted_entries[$all_entry['type']]['quantity_entries']++;

                $sorted_entries[$all_entry['type']]['time_taken_entries'] += $all_entry['time_taken'];
            }
        }

        if (!empty($entry_type)) {
            return array_intersect_key($sorted_entries, array($entry_type => array()));
        }

		return $sorted_entries;
	}

    public function setRegistries($registry) {
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->request = $registry->get('request');
		$this->session = $registry->get('session');
    }

    public function getStartTime() {
        return $this->start_time;
    }

    public function getFinishTime() {
        return $this->finish_time;
    }

    public function setStartTime($start_time) {
        $this->start_time = $start_time;
    }

    public function setFinishTime($finish_time) {
        $this->finish_time = $finish_time;
    }

    public function getStartMemory() {
        return $this->start_memory;
    }

    public function getFinishMemory() {
        return $this->finish_memory;
    }

    public function setStartMemory($start_memory) {
        $this->start_memory = $start_memory;
    }

    public function setFinishMemory($finish_memory) {
        $this->finish_memory = $finish_memory;
    }

    public function getResponseCode() {
        return $this->response_code;
    }

    public function setResponseCode($response_code) {
        $this->response_code = $response_code;
    }

    public function getMainController() {
        return $this->main_controller;
    }

    public function getMainMethod() {
        return $this->main_method;
    }

    public function setMainController($main_controller) {
        $this->main_controller = $main_controller;
    }

    public function setMainMethod($main_method) {
        $this->main_method = $main_method;
    }
}
?>