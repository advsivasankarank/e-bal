<?php

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
                    <td>".number_format($r['amount'],2)."</td>
                  </tr>";

        $total += $r['amount'];
    }

    $html .= "<tr><td><b>Total</b></td><td><b>".number_format($total,2)."</b></td></tr>";
    $html .= "</table>";

    return $html;
}