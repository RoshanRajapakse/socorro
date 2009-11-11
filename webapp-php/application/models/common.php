<?php defined('SYSPATH') or die('No direct script access.');

require_once(Kohana::find_file('libraries', 'moz_pager', TRUE, 'php'));

/**
 * Common DB queries that span multiple tables and perform aggregate calculations or statistics.
 */
class Common_Model extends Model {

    /**
     * Perform overall initialization for the model.
     */
    public function __construct() {
        parent::__construct();

        $this->branch_model   = new Branch_Model();
        $this->platform_model = new Platform_Model();
    }

    /**
     *
     */
   /**
     * Fetch all of the comments associated with a particular Crash Signature.
     *
     * @access 	public
     * @param   string A Crash Signature
     * @return  array 	An array of comments
     * @see getCommentsBySignature
     */
    public function getCommentsBySignature($signature) {
        $params = array('signature' => $signature, 
			'range_value' => 2, 'range_unit' => 'weeks',
			'product' => NULL,  'version' => NULL, 
			'branch' => NULL,   'platform' => NULL,
			'query' => NULL, 'date' => NULL);
	return $this->getCommentsByParams($params);
    }
    /**
     * Fetch all of the comments associated with a particular Crash Signature.
     * 
     * @access 	public
     * @param	array 	An array of parameters
     * @return  array 	An array of comments
     */
    public function getCommentsByParams($params) {
        $tables = array();
        $where = array();

        list($params_tables, $params_where) = $this->_buildCriteriaFromSearchParams($params);
        $tables += $params_tables;
        $where  += $params_where;

        $sql =
	    "/* soc.web report.getCommentsBySignature.1. */ " .
            " SELECT 
				reports.client_crash_date, 
				reports.user_comments, 
				CASE 
					WHEN reports.email = '' THEN null
					WHEN reports.email IS NULL THEN null
					ELSE reports.email
					END " .
            " FROM  " . join(', ', array_keys($tables)) .
            " WHERE reports.user_comments IS NOT NULL " . 
 			" AND " . join(' AND ', $where) .
			" ORDER BY email ASC, reports.client_crash_date ASC ";
		return $this->fetchRows($sql);
    }

    /**
     * Find top report signatures for the given search parameters.
     */
    public function queryTopSignatures($params) {

        $columns = array(
            'reports.signature', 'count(reports.id)'
        );
        $tables = array(
        );
        $where = array(
        );

        $platforms = $this->platform_model->getAll();
        foreach ($platforms as $platform) {
            $columns[] = 
                "count(CASE WHEN (reports.os_name = '{$platform->os_name}') THEN 1 END) ".
                "AS is_{$platform->id}";
        }

        list($params_tables, $params_where) = 
            $this->_buildCriteriaFromSearchParams($params);

        $tables += $params_tables;
        $where  += $params_where;

        $sql =
	    "/* soc.web common.queryTopSig. */ " .
            " SELECT " . join(', ', $columns) .
            " FROM   " . join(', ', array_keys($tables)) .
            " WHERE  " . join(' AND ', $where) .
            " GROUP BY reports.signature " .
            " ORDER BY count(reports.id) DESC " .
            " LIMIT 100";

        return $this->fetchRows($sql);
    }

    /**
     * Find total number of crash reports for the given search parameters.
     * @param array Parameters that vary
     * @pager object optional MozPager instance
     * @return int total number of crashes
     */
    public function totalNumberReports($params) {
        $tables = array();
        $where = array();

        list($params_tables, $params_where) = 
            $this->_buildCriteriaFromSearchParams($params);

        $tables += $params_tables;
        $where  += $params_where;

        $sql = "/* soc.web common.totalQueryReports */ 
            SELECT COUNT(uuid) as total
            FROM   " . join(', ', array_keys($tables)) .
          " WHERE  " . join(' AND ', $where);
	$rs = $this->fetchRows($sql);
	if ($rs && count($rs) > 0) {
	    return $rs[0]->total;
	} else {
	    return 0;
	}
    }

    /**
     * Find all crash reports for the given search parameters and
     * paginate the results.
     * 
     * @param array Parameters that vary
     * @pager object optional MozPager instance
     * @return array of objects
     */
    public function queryReports($params, $pager=NULL) {
	if ($pager === NULL) {
	    $pager = new stdClass;
	    $pager->offset = 0;
	    $pager->itemsPerPage = Kohana::config('search.number_report_list');
	    $pager->currentPage = 1;
	}

        $columns = array(
            'reports.date_processed',
            'reports.uptime',
            'reports.user_comments',
            'reports.uuid',
            'reports.product',
            'reports.version',
            'reports.build',
            'reports.signature',
            'reports.url',
            'reports.os_name',
            'reports.os_version',
            'reports.cpu_name',
            'reports.cpu_info',
            'reports.address',
            'reports.reason',
            'reports.last_crash',
            'reports.install_age'
        );
        $tables = array(
        );
        $where = array(
        );

        list($params_tables, $params_where) = 
            $this->_buildCriteriaFromSearchParams($params);

        $tables += $params_tables;
        $where  += $params_where;

        $sql = "/* soc.web common.queryReports */ 
            SELECT " . join(', ', $columns) .
          " FROM   " . join(', ', array_keys($tables)) .
          " WHERE  " . join(' AND ', $where) .
	  " ORDER BY reports.date_processed DESC 
	    LIMIT ? OFFSET ? ";

        return $this->fetchRows($sql, TRUE, array($pager->itemsPerPage, $pager->offset));
    }

    /**
     * Calculate frequency of crashes across builds and platforms.
     */
    public function queryFrequency($params) {

        $signature = $this->db->escape($params['signature']);

        $columns = array(
            "date_trunc('day', reports.build_date) AS build_date",
            "count(CASE WHEN (reports.signature = $signature) THEN 1 END) AS count",
            "CAST(count(CASE WHEN (reports.signature = $signature) THEN 1 END) AS FLOAT(10)) / count(reports.id) AS frequency", 
            "count(reports.id) AS total"
        );
        $tables = array(
        );
        $where = array(
        );

        $platforms = $this->platform_model->getAll();
        foreach ($platforms as $platform) {
            $columns[] = 
                "count(CASE WHEN (reports.signature = $signature AND reports.os_name = '{$platform->os_name}') THEN 1 END) AS count_{$platform->id}";
            $columns[] = 
                "CASE WHEN (count(CASE WHEN (reports.os_name = '{$platform->os_name}') THEN 1 END) > 0) THEN (CAST(count(CASE WHEN (reports.signature = $signature AND reports.os_name = '{$platform->os_name}') THEN 1 END) AS FLOAT(10)) / count(CASE WHEN (reports.os_name = '{$platform->os_name}') THEN 1 END)) ELSE 0.0 END AS frequency_{$platform->id}";
        }

        list($params_tables, $params_where) = 
            $this->_buildCriteriaFromSearchParams($params);

        $tables += $params_tables;
        $where  += $params_where;

        $sql =
            "/* soc.web common.queryFreq */ " .
            " SELECT " . join(', ', $columns) .
            " FROM   " . join(', ', array_keys($tables)) .
            " WHERE  " . join(' AND ', $where) .
            " GROUP BY date_trunc('day', reports.build_date) ".
            " ORDER BY date_trunc('day', reports.build_date) DESC";

        return $this->fetchRows($sql);
    }

    /**
     * Build the WHERE part of a DB query based on search from parameters.
     */
    public function _buildCriteriaFromSearchParams($params) {

        $tables = array( 
            'reports' => 1 
        );
        $where  = array(
            'reports.signature IS NOT NULL'
        );

        if (isset($params['signature'])) {
            $where[] = 'reports.signature = ' . $this->db->escape($params['signature']);
        }

        if ($params['product']) {
            $or = array();
            foreach ($params['product'] as $product) {
                $or[] = "reports.product = " . $this->db->escape($product);
            }
            $where[] = '(' . join(' OR ', $or) . ')';
        }

        if ($params['branch']) {
            $tables['branches'] = 1;
            $or = array();
            foreach ($params['branch'] as $branch) {
                $or[] = "branches.branch = " . $this->db->escape($branch);
            }
            $where[] = '(' . join(' OR ', $or) . ')';
            $where[] = 'branches.product = reports.product';
            $where[] = 'branches.version = reports.version';
        }

        if ($params['version']) {
            $or = array();
            foreach ($params['version'] as $spec) {
                list($product, $version) = split(':', $spec);
                $or[] = 
                    "(reports.product = " . $this->db->escape($product) . " AND " .
                    "reports.version = " . $this->db->escape($version) . ")";
            }
            $where[] = '(' . join(' OR ', $or) . ')';
        }

        if ($params['platform']) {
	    $or = array();
            foreach ($params['platform'] as $platform_id) {
                $platform = $this->platform_model->get($platform_id);
                if ($platform) {
		    $or[] = 'reports.os_name = ' . $this->db->escape($platform->os_name);
                }
            }
	    $where[] = '(' . join(" OR ", $or) . ')';
        }

        if ($params['query']) {

            $term = FALSE;

            switch ($params['query_type']) {
                case 'exact':
                    $term = ' = ' . $this->db->escape($params['query']); break;
                case 'startswith':
                    $term = ' LIKE ' . $this->db->escape($params['query'].'%'); break;
                case 'contains':
                default:
                    $term = ' LIKE ' . $this->db->escape('%'.$params['query'].'%'); break;
            }

            if ($params['query_search'] == 'signature') {
                $where[] = 'reports.signature ' . $term;
            } else if ($params['query_search'] == 'stack') {
                $where[] = "(EXISTS (" .
                    "SELECT 1 FROM frames " . 
                    "WHERE frames.signature $term " .
                    "AND frames.report_id = reports.id" .
                    "))";
            }

        }

        if ($params['range_value'] && $params['range_unit']) {
            if (!$params['date']) {
                $interval = $this->db->escape($params['range_value'] . ' ' . $params['range_unit']);
                $now = date('Y-m-d H:i:s');
                $where[] = "reports.date_processed BETWEEN TIMESTAMP '$now' - CAST($interval AS INTERVAL) AND TIMESTAMP '$now'";
            } else {
                $date = $this->db->escape($params['date']);
                $interval = $this->db->escape($params['range_value'] . ' ' . $params['range_unit']);
                $where[] = "reports.date_processed BETWEEN CAST($date AS DATE) - CAST($interval AS INTERVAL) AND CAST($date AS DATE)";
            }
        }
        
        return array($tables, $where);
    }

}
