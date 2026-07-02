<?php

require_once __DIR__ . '/helpers/figure_helper.php';

function buildNotes($pdo, $company_id, $data)
{
    $notes = [];

    foreach ($data as $row) {

        $code = getMapping($pdo, $company_id, $row['name']);
        if (!$code) continue;

        $notes[$code][] = $row;
    }

    return $notes;
}

function formatNote($title, $rows)
{
    $html = "<table class='note-table'>";
    $html .= "<tr><th>Particulars</th><th>Amount</th></tr>";

    $total = 0;

    foreach ($rows as $r) {
        $html .= "<tr>
                    <td>{$r['name']}</td>
                    <td>".format_inr_number($r['amount'])."</td>
                  </tr>";

        $total += $r['amount'];
    }

    $html .= "<tr><td><b>Total</b></td><td><b>".format_inr_number($total)."</b></td></tr>";
    $html .= "</table>";

    return $html;
}