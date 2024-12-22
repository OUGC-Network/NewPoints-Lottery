<?php

use function Newpoints\Core\log_add;
use function Newpoints\Core\points_add_simple;

use const Newpoints\Core\LOGGING_TYPE_INCOME;

/***************************************************************************
 *
 *   NewPoints Lottery plugin (/inc/plugins/tasks/newpoints_lottery.php)
 *     Author: Diogo Parrinha
 *   Copyright: (c) 2021 Diogo Parrinha
 *
 *   Integrates a lottery system with NewPoints.
 *
 ***************************************************************************/

function task_newpoints_lottery($task)
{
    global $mybb, $db, $lang, $cache;

    $db->update_query('tasks', ['locked' => 0], 'tid=18');

    // get current term data from cache (in this function we only need 'start_time')
    $term = $cache->read('lottery_term');
    if (!empty($term)) {
        // If it's time to find a winner, do so
        if (TIME_NOW > $term['start_time'] + $mybb->settings['newpoints_lottery_draw_frequency']) {
            // Find a winner!
            $query = $db->simple_select(
                'newpoints_lottery_tickets',
                '*',
                'term_id=' . intval($term['term_id']),
                ['order_by' => 'rand()', 'limit' => 1]
            );
            $winner_ticket = $db->fetch_array($query);
            if (!empty($winner_ticket)) {
                $points = newpoints_lottery_money();

                // We've found a winner, time to give some points to the user (on shutdown)
                points_add_simple((int)$winner_ticket['uid'], $points);

                // Load language
                newpoints_lang_load('newpoints_lottery');

                // get winner username
                $username = $db->fetch_field(
                    $db->simple_select('users', 'username', 'uid=\'' . intval($winner_ticket['uid']) . '\''),
                    'username'
                );

                // log winner
                log_add(
                    'lottery_winner',
                    '',
                    $username ?? '',
                    (int)$winner_ticket['uid'],
                    $points,
                    (int)$winner_ticket['ticket_id'],
                    (int)$term['term_id'],
                    0,
                    LOGGING_TYPE_INCOME
                );

                $row = [
                    'winner_uid' => $winner_ticket['uid'],
                    'winner_ticket_number' => $winner_ticket['ticket_id'],
                    'money' => (float)$points,
                    'end_time' => TIME_NOW // draw time
                ];
                $db->update_query('newpoints_lottery_term', $row, 'term_id=\'' . intval($term['term_id']) . '\'');
            }

            // Create a new term
            $row = [
                'winner_uid' => 0,
                'winner_ticket_number' => 0,
                'money' => 0,
                'end_time' => 0,
                'start_time' => (TIME_NOW + $mybb->settings['newpoints_lottery_rest'])
                // the results are shown until then
            ];
            $term_id = (int)$db->insert_query('newpoints_lottery_term', $row);

            // Reset cache
            $cache->update(
                'lottery_term',
                [
                    'term_id' => $term_id,
                    'start_time' => TIME_NOW + $mybb->settings['newpoints_lottery_rest'],
                    'ticket_count' => 0
                ]
            );
            $cache->update('lottery_pot', [0]);
        }
    }

    add_task_log($task, $lang->newpoints_lottery_task_ran);
}