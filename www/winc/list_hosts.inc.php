<?



//////////////////////////////////////////////////////////////////////////////
// Function: ws_display_list()
//
// Description:
//   Displays A list of hosts based on search criteria.
//   Input:  An array from xajaxGetFormValues() from a quick filter form.
//////////////////////////////////////////////////////////////////////////////
function ws_display_list($window_name, $form='') {
    global $conf, $self, $onadb;
    global $images, $color, $style;
    $html = '';
    $js = '';

    // If the user supplied an array in a string, transform it into an array
    $form = parse_options_string($form);

    // Find the "tab" we're on
    $tab = $_SESSION['ona'][$form['form_id']]['tab'];

    // Build js to refresh this list
    $refresh = "xajax_window_submit('{$window_name}', xajax.getFormValues('{$form['form_id']}'), 'display_list');";

    // If it's not a new query, load the previous query from the session
    // into $form and save the current page and filter in the session.
    // Also find/set the "page" we're viewing
    $page = 1;
    if ($form['page'] and is_numeric($form['page'])) {
        $form = array_merge($form, (array)$_SESSION['ona'][$form['form_id']][$tab]['q']);
        $_SESSION['ona'][$form['form_id']][$tab]['page'] = $page = $form['page'];
        $_SESSION['ona'][$form['form_id']][$tab]['filter'] = $form['filter'];
    }
    printmsg("DEBUG => Displaying hosts list page: {$page}", 1);

    // Calculate the SQL query offset (based on the page being displayed)
    $offset = ($conf['search_results_per_page'] * ($page - 1));
    if ($offset == 0) { $offset = -1; }

    // Search results go in here
    $results = array();
    $count = 0;




    //
    // *** ADVANCED HOST SEARCH ***
    //       FIND RESULT SET
    //

    // Start building the "where" clause for the sql query to find the hosts to display
    $where = "";
    $and = "";
    $orderby = "";

    // HOST ID
    if ($form['host_id']) {
        $where .= $and . "id = " . $onadb->qstr($form['host_id']);
        $and = " AND ";
    }


    // HOSTNAME
    $aliases = 0;
    if ($form['hostname']) {
        // Find the domain name piece of the hostname.
        // FIXME: MP this was taken from the ona_find_domain function. make that function have the option
        // to NOT return a default domain.

        // Split it up on '.' and put it in an array backwards
        $parts = array_reverse(explode('.', $form['hostname']));
    
        // Find the domain name that best matches
        $name = '';
        $domain = array();
        foreach ($parts as $part) {
            if (!$rows) {
                if (!$name) $name = $part;
                else $name = "{$part}.{$name}";
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $name));
                if ($rows)
                    $domain = $record;
            }
            else {
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $part, 'parent_id' => $domain['id']));
                if ($rows)
                    $domain = $record;
            }
        }

        $withdomain = '';
        $hostname = $form['hostname'];
        if (array_key_exists('id', $domain)) {
            $withdomain = "AND domain_id = {$domain['id']}";
            // Now find what the host part of $search is
            $hostname = str_replace(".{$domain['fqdn']}", '', $form['hostname']);
        }
        // TODO: MP this seems to be kinda slow (gee I wonder why).. look into speeding things up somehow.
        //       This also does not search for CNAME records etc.  only things with interface_id.. how to fix that issue.......?
        $where .= $and . "id IN (select host_id from interfaces where id in (SELECT interface_id " .
                                "  FROM dns " .
                                "  WHERE name LIKE '%{$hostname}%' {$withdomain} ))";
        $and = " AND ";
    }


    // DOMAIN
    if ($form['domain']) {
        // FIXME: does this clause work correctly?
        printmsg("FIXME: => Does \$form['domain'] work correctly in list_hosts.inc.php, line 79?", 2);
        // Find the domain name piece of the hostname.
        // FIXME: MP this was taken from the ona_find_domain function. make that function have the option
        // to NOT return a default domain.

        // Split it up on '.' and put it in an array backwards
        $parts = array_reverse(explode('.', $form['domain']));
    
        // Find the domain name that best matches
        $name = '';
        $domain = array();
        foreach ($parts as $part) {
            if (!$rows) {
                if (!$name) $name = $part;
                else $name = "{$part}.{$name}";
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $name));
                if ($rows)
                    $domain = $record;
            }
            else {
                list($status, $rows, $record) = ona_get_domain_record(array('name' => $part, 'parent_id' => $domain['id']));
                if ($rows)
                    $domain = $record;
            }
        }

        if (array_key_exists('id', $domain)) {
    
            // We do a sub-select to find id's that match
            $where .= $and . "id IN (SELECT host_id ".
                                       "FROM interfaces " .
                                        "WHERE id IN (" .
                                            "( SELECT interface_id " .
                                            "  FROM dns " .
                                            "  WHERE domain_id = " . $onadb->qstr($domain['id']) . " )) )";
            $and = " AND ";
        }
    }

    // DOMAIN ID
    if ($form['domain_id']) {
        $where .= $and . "primary_dns_id IN ( SELECT id " .
                                            "  FROM dns " .
                                            "  WHERE domain_id = " . $onadb->qstr($form['domain_id']) . " )  ";
        $and = " AND ";
    }

    // subnet ID
    if (is_numeric($form['subnet_id'])) {
        // We do a sub-select to find interface id's that match
        $where .= $and . "id IN ( SELECT host_id " .
                                 "  FROM interfaces " .
                                 "  WHERE subnet_id = " . $onadb->qstr($form['subnet_id']) . " ) ";
        $and = " AND ";
    }


    // MAC
    if ($form['mac']) {
        // Clean up the mac address
        $form['mac'] = strtoupper($form['mac']);
        $form['mac'] = preg_replace('/[^%0-9A-F]/', '', $form['mac']);

        // We do a sub-select to find interface id's that match
        $where .= $and . "id IN ( SELECT host_id " .
                         "        FROM interfaces " .
                         "        WHERE mac_addr LIKE " . $onadb->qstr('%'.$form['mac'].'%') . " ) ";
        $and = " AND ";

    }


    // IP ADDRESS
    $ip = $ip_end = '';
    if ($form['ip']) {
        // Build $ip and $ip_end from $form['ip'] and $form['ip_thru']
        $ip = ip_complete($form['ip'], '0');
        if ($form['ip_thru']) { $ip_end = ip_complete($form['ip_thru'], '255'); }
        else { $ip_end = ip_complete($form['ip'], '255'); }

        // Find out if $ip and $ip_end are valid
        $ip = ip_mangle($ip, 'numeric');
        $ip_end = ip_mangle($ip_end, 'numeric');
        if ($ip != -1 and $ip_end != -1) {
            // We do a sub-select to find interface id's between the specified ranges
            $where .= $and . "id IN ( SELECT host_id " .
                             "        FROM interfaces " .
                             "        WHERE ip_addr >= " . $onadb->qstr($ip) . " AND ip_addr <= " . $onadb->qstr($ip_end) . " )";
            $and = " AND ";
        }
    }


    // NOTES
    if ($form['notes']) {
        $where .= $and . "notes LIKE " . $onadb->qstr('%'.$form['notes'].'%');
        $and = " AND ";
    }




    // DEVICE MODEL
    if ($form['model']) {
        $where .= $and . "device_id in (select id from devices where device_type_id in (select id from device_types
                        where model_id = {$form['model']}))";
        $and = " AND ";
    }


    // DEVICE TYPE
    if ($form['role']) {
        // Find model_id's that have a device_type_id of $form['role']
        list($status, $rows, $records) =
            db_get_records($onadb,
                           'roles',
                           array('id' => $form['role'])
                          );
        // If there were results, add each one to the $where clause
        if ($rows > 0) {
            $where .= $and . " ( ";
            $and = " AND ";
            $or = "";
            foreach ($records as $record) {
                // Yes this is one freakin nasty query but it works.
                $where .= $or . "device_id in (select id from devices where device_type_id in (select id from device_types
                        where role_id = " . $onadb->qstr($record['id']) ."))";
                $or = " OR ";
            }
            $where .= " ) ";
        }
    }


    // DEVICE MANUFACTURER
    if ($form['manufacturer']) {
        // Find model_id's that have a device_type_id of $form['manufacturer']
        list($status, $rows, $records) =
            db_get_records($onadb,
                           'models',
                           array('manufacturer_id' => $form['manufacturer'])
                          );
        // If there were results, add each one to the $where clause
        if ($rows > 0) {
            $where .= $and . " ( ";
            $and = " AND ";
            $or = "";
            foreach ($records as $record) {
                // Yes this is one freakin nasty query but it works.
                $where .= $or . "device_id in (select id from devices where device_type_id in (select id from device_types
                        where model_id = " . $onadb->qstr($record['id']) ."))";
                $or = " OR ";
            }
            $where .= " ) ";
        }
    }


    // LOCATION No.
    if ($form['location']) {
        $where .= $and . "location_id = " . $onadb->qstr($form['location']);
        $and = " AND ";
    }





    // HIJACK!!!
    // If $form['type'] == aliases, jump to that function
    if ($form['type'] == 'aliases') {
        $form['host_where'] = $where;
        return(ws_display_alias_list($window_name, $form));
    }


    // Wild card .. if $while is still empty, add a 'ID > 0' to it so you see everything.
    if ($where == '')
        $where = 'id > 0';


    // Do the SQL Query
    $filter = '';
    if ($form['filter']) {

        // Host names should always be lower case
        $form['filter'] = strtolower($form['filter']);
        // FIXME (MP) for now this uses primary_dns_id, this will NOT find multiple A records or other record types. Find a better way some day
        $filter = " AND primary_dns_id IN  (SELECT id " .
                                            " FROM dns " .
                                            " WHERE name LIKE ". $onadb->qstr('%'.$form['filter'].'%') . " )  ";

    }
    list ($status, $rows, $results) =
        db_get_records(
            $onadb,
            'hosts',
            $where . $filter,
            $orderby,
            $conf['search_results_per_page'],
            $offset
        );

    // If we got less than serach_results_per_page, add the current offset to it
    // so that if we're on the last page $rows still has the right number in it.
    if ($rows > 0 and $rows < $conf['search_results_per_page']) {
        $rows += ($conf['search_results_per_page'] * ($page - 1));
    }

    // If there were more than $conf['search_results_per_page'] find out how many records there really are
    else if ($rows >= $conf['search_results_per_page']) {
        list ($status, $rows, $records) =
            db_get_records(
                $onadb,
                'hosts',
                $where . $filter,
                "",
                0
            );
    }
    $count = $rows;

   // $js .= "alert('Where: " . str_replace("'", '"', $status) . "');";





    //
    // *** BUILD HTML LIST ***
    //
    $html .= <<<EOL
        <!-- Host Results -->
        <table id="{$form['form_id']}_host_list" class="list-box" cellspacing="0" border="0" cellpadding="0">

            <!-- Table Header -->
            <tr>
                <td class="list-header" align="center" style="{$style['borderR']};">Name</td>
                <td class="list-header" align="center" style="{$style['borderR']};">Subnet</td>
                <td class="list-header" align="center" style="{$style['borderR']};">Interface</td>
                <td class="list-header" align="center" style="{$style['borderR']};">Device Model</td>
                <td class="list-header" align="center" style="{$style['borderR']};">Location</td>
                <td class="list-header" align="center" style="{$style['borderR']};">Notes</td>
                <td class="list-header" align="center">&nbsp;</td>
            </tr>
EOL;
    // Loop and display each record
    foreach($results as $record) {
        // Get additional info about eash host record


        // If a subnet_id was passed use it as part of the search.  Used to display the IP of the subnet you searched
        if (is_numeric($form['subnet_id'])) {
            list($status, $interfaces, $interface) = ona_get_interface_record(array('host_id' => $record['id'], 'subnet_id' => $form['subnet_id']), '');

            // Count how many rows and assign it back to the interfaces variable
            list($status, $rows, $records) = db_get_records($onadb,
                                                            'interfaces',
                                                            'host_id = '. $onadb->qstr($record['id']),
                                                            "",
                                                            0);

            $interfaces = $rows;

        } else if (is_numeric($ip)) {
            list($status, $interfaces, $interface) = db_get_record($onadb,
                                                            'interfaces',
                                                            'host_id = '. $onadb->qstr($record['id']) .
                                                            ' AND ip_addr >= ' . $onadb->qstr($ip) .
                                                            ' AND ip_addr <= ' . $onadb->qstr($ip_end),
                                                            "",
                                                            0);

            // Count how many rows and assign it back to the interfaces variable
            list($status, $rows, $records) = db_get_records($onadb,
                                                            'interfaces',
                                                            'host_id = '. $onadb->qstr($record['id']),
                                                            "",
                                                            0);

            $interfaces = $rows;

        }  else {
            // Interface (and find out how many there are)
            list($status, $interfaces, $interface) = ona_get_interface_record(array('host_id' => $record['id']), '');
        }

        // bz: why did someone add this??  You especially want to show hosts with no interfaces so you can fix them!
        // if (!$interfaces) {$count -1; continue;}

        // get interface cluster info
        $clusterhtml = '';
        list ($status, $intclusterrows, $intcluster) = db_get_records($onadb, 'interface_clusters', "interface_id = {$interface['id']}");
        if ($intclusterrows>0) {
            $clusterscript= "onMouseOver=\"wwTT(this, event,
                    'id', 'tt_interface_cluster_list_{$record['id']}',
                    'type', 'velcro',
                    'styleClass', 'wwTT_niceTitle',
                    'direction', 'south',
                    'javascript', 'xajax_window_submit(\'tooltips\', \'tooltip=>interface_cluster_list,id=>tt_interface_cluster_list_{$record['id']},interface_id=>{$interface['id']}\');'
                    );\"";
            $clusterhtml .= <<<EOL
                <img src="{$images}/silk/sitemap.png" {$clusterscript} />
EOL;
        }


        $record['ip_addr'] = ip_mangle($interface['ip_addr'], 'dotted');
        $interface_style = '';
        if ($interfaces > 1) $interface_style = 'font-weight: bold;';

        // DNS A record
        list($status, $rows, $dns) = ona_get_dns_record(array('id' => $record['primary_dns_id']));
        $record['name'] = $dns['name'];

        // Domain Name
        list($status, $rows, $domain) = ona_get_domain_record(array('id' => $dns['domain_id']));
        $record['domain'] = $domain['fqdn'];

        // Subnet description
        list($status, $rows, $subnet) = ona_get_subnet_record(array('id' => $interface['subnet_id']));
        $record['subnet'] = $subnet['name'];
        $record['ip_mask'] = ip_mangle($subnet['ip_mask'], 'dotted');
        $record['ip_mask_cidr'] = ip_mangle($subnet['ip_mask'], 'cidr');


        // Device Description
        list($status, $rows, $device) = ona_get_device_record(array('id' => $record['device_id']));
        list($status, $rows, $device_type) = ona_get_device_type_record(array('id' => $device['device_type_id']));
        list($status, $rows, $model) = ona_get_model_record(array('id' => $device_type['model_id']));
        list($status, $rows, $role) = ona_get_role_record(array('id' => $device_type['role_id']));
        list($status, $rows, $manufacturer) = ona_get_manufacturer_record(array('id' => $model['manufacturer_id']));
        $record['devicefull'] = "{$manufacturer['name']}, {$model['name']} ({$role['name']})";
        $record['device'] = str_replace('Unknown', '?', $record['devicefull']);


        $record['notes_short'] = truncate($record['notes'], 40);

        // Get location_number from the location_id
        list($status, $rows, $location) = ona_get_location_record(array('id' => $record['location_id']));

        // Escape data for display in html
        foreach(array_keys($record) as $key) { $record[$key] = htmlentities($record[$key], ENT_QUOTES); }

        $primary_object_js = "xajax_window_submit('work_space', 'xajax_window_submit(\'display_host\', \'host_id=>{$record['id']}\', \'display\')');";
        $html .= <<<EOL
            <tr onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';">

                <td class="list-row">
                    <a title="View host. ID: {$record['id']}"
                       class="nav"
                       onClick="{$primary_object_js}"
                    >{$record['name']}</a
                    >.<a title="View domain. ID: {$domain['id']}"
                         class="domain"
                         onClick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_domain\', \'domain_id=>{$domain['id']}\', \'display\')');"
                    >{$record['domain']}</a>
                </td>

                <td class="list-row">
                    <a title="View subnet. ID: {$subnet['id']}"
                         class="nav"
                         onClick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_subnet\', \'subnet_id=>{$subnet['id']}\', \'display\')');"
                    >{$record['subnet']}</a>&nbsp;
                </td>

                <td class="list-row" align="left">
                    <span style="{$interface_style}"
EOL;

if ($interfaces > 1) {
        $html .= <<<EOL
                          onMouseOver="wwTT(this, event,
                                            'id', 'tt_host_interface_list_{$record['id']}',
                                            'type', 'velcro',
                                            'styleClass', 'wwTT_niceTitle',
                                            'direction', 'south',
                                            'javascript', 'xajax_window_submit(\'tooltips\', \'tooltip=>host_interface_list,id=>tt_host_interface_list_{$record['id']},host_id=>{$record['id']}\');'
                                           );"
EOL;
}
        $html .= <<<EOL
                    >{$record['ip_addr']}</span>&nbsp;
                    <span title="{$record['ip_mask']}">/{$record['ip_mask_cidr']}</span>
                    <span>{$clusterhtml}</span>
                </td>

                <td class="list-row" title="{$record['devicefull']}">{$record['device']}&nbsp;</td>

                <td class="list-row" align="right">
                    <span onMouseOver="wwTT(this, event,
                                            'id', 'tt_location_{$record['location_id']}',
                                            'type', 'velcro',
                                            'styleClass', 'wwTT_niceTitle',
                                            'direction', 'south',
                                            'javascript', 'xajax_window_submit(\'tooltips\', \'tooltip=>location,id=>tt_location_{$record['location_id']},location_id=>{$record['location_id']}\');'
                                           );"
                    >{$location['reference']}</span>&nbsp;
                </td>

                <td class="list-row">
                    <span title="{$record['notes']}">{$record['notes_short']}</span>&nbsp;
                </td>

                <!-- ACTION ICONS -->
                <td class="list-row" align="right">
                    <form id="{$form['form_id']}_list_host_{$record['id']}"
                        ><input type="hidden" name="host_id" value="{$record['id']}"
                        ><input type="hidden" name="js" value="{$refresh}"
                    ></form>&nbsp;
EOL;

        if (auth('host_modify')) {
            $html .= <<<EOL

                    <a title="Edit host"
                       class="act"
                       onClick="xajax_window_submit('edit_host', xajax.getFormValues('{$form['form_id']}_list_host_{$record['id']}'), 'editor');"
                    ><img src="{$images}/silk/page_edit.png" border="0"></a>&nbsp;
EOL;
        }

        if (auth('host_del')) {
            $html .= <<<EOL

                    <a title="Delete host"
                       class="act"
                       onClick="var doit=confirm('Are you sure you want to delete this host?');
                                if (doit == true)
                                    xajax_window_submit('edit_host', xajax.getFormValues('{$form['form_id']}_list_host_{$record['id']}'), 'delete');"
                    ><img src="{$images}/silk/delete.png" border="0"></a>
EOL;
        }
        $html .= <<<EOL
                    &nbsp;
                </td>

            </tr>
EOL;

    }



    if ($count == 0 and $form['subnet_id'] and !$form['filter']) {
        $html .= <<<EOL
     <tr><td colspan="99" align="center" style="color: red;">Please add the gateway host (router) to this subnet</td></tr>
EOL;
    }

    $html .= <<<EOL
    </table>
EOL;

    // Build page links if there are any
    $html .= get_page_links($page, $conf['search_results_per_page'], $count, $window_name, $form['form_id']);

    $js .= <<<EOL
            /* Make sure this table is 100% wide */
            el('{$form['form_id']}_host_list').style.width = el('{$form['form_id']}_table').offsetWidth + 'px';
            /* Hack to Make sure the other tables are 100% wide */
            if (el('list_dhcp_leases_filter_form_dhcp_lease_list'))
                el('list_dhcp_leases_filter_form_dhcp_lease_list').style.width = el('list_dhcp_leases_filter_form_table').offsetWidth + 'px';
EOL;

    // If there was only 1 result, and we're about to display results in the "Search Results" window, display it.
    if ($count == 1 and $aliases == 0 and $form['content_id'] == 'search_results_list' and $form['filter'] == '')
        $js .= $primary_object_js;

    // Insert the new html into the content div specified
    // Instantiate the xajaxResponse object
    $response = new xajaxResponse();
    $response->addAssign("{$form['form_id']}_{$tab}_count",  "innerHTML", "({$count})");
    $response->addAssign($form['content_id'], "innerHTML", $html);
    if ($js) { $response->addScript($js); }
    return($response->getXML());
}







?>
