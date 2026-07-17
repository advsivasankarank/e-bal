<?php
$sectionTitles = getDirectorsReportSectionDefinitions();
$fyEndDateForTitle = isset($fyName) ? directorsReportFyEndDate((string) $fyName) : '';
$financialPerformanceRows = buildDirectorsReportFinancialPerformanceRows($data ?? []);
?>

<div class="directors-report-preview">
    <h2>Directors' Report</h2>
    <p>To,<br>The Members of <?= htmlspecialchars($companyName) ?></p>

    <p><?= nl2br(htmlspecialchars((string) ($sections['intro'] ?? ''))) ?></p>

    <h3 class="financial-performance-heading">Financial Performance</h3>
    <table class="directors-report-table" border="1" width="100%" cellpadding="5">
        <thead>
        <tr>
            <th class="particulars" rowspan="2">Particulars</th>
            <th class="figure" colspan="2">Amount (in Rs.)</th>
        </tr>
        <tr>
            <th class="figure">Current Year<?= $fyEndDateForTitle !== '' ? ' (' . htmlspecialchars($fyEndDateForTitle) . ')' : '' ?></th>
            <th class="figure">Previous Year</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($financialPerformanceRows as $row): ?>
        <tr<?= !empty($row['bold']) ? ' class="total-row"' : '' ?>>
            <td><?= !empty($row['bold']) ? '<b>' . htmlspecialchars($row['label']) . '</b>' : htmlspecialchars($row['label']) ?></td>
            <td class="figure"><?= !empty($row['bold']) ? '<b>' . \format_inr((float) $row['current']) . '</b>' : \format_inr((float) $row['current']) ?></td>
            <td class="figure"><?= !empty($row['bold']) ? '<b>' . \format_inr((float) $row['previous']) . '</b>' : \format_inr((float) $row['previous']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php $counter = 1; foreach ($sectionTitles as $key => $title): if ($key === 'intro') { $counter++; continue; } ?>
        <h3><?= $counter ?>. <?= htmlspecialchars($title) ?></h3>
        <div class="report-section-text"><?= nl2br(htmlspecialchars((string) ($sections[$key] ?? ''))) ?></div>
        <?php $counter++; ?>
    <?php endforeach; ?>

    <div class="directors-report-signoff">
        <strong>For and on behalf of the Board of Directors of</strong><br>
        <strong><?= htmlspecialchars(strtoupper($companyName)) ?></strong>
        <?php if (!empty($company_meta['cin'])): ?>
        <br>CIN: <?= htmlspecialchars($company_meta['cin']) ?>
        <?php endif; ?>
    </div>

    <table width="100%" style="border:0; border-collapse:collapse; margin-top:28px;">
        <tr>
            <td style="width:50%; border:0; padding:0; vertical-align:top;">
                <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Director 1') ?></strong><br>
                <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Director') ?><br>
                <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?>
            </td>
            <td style="width:50%; border:0; padding:0; vertical-align:top; text-align:right;">
                <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Director 2') ?></strong><br>
                <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Director') ?><br>
                <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?>
            </td>
        </tr>
    </table>

    <?php if (!empty($directorsReportPlace) || !empty($directorsReportDate)): ?>
    <div class="directors-report-place-date">
        <?php if (!empty($directorsReportPlace)): ?><div>Place: <?= htmlspecialchars($directorsReportPlace) ?></div><?php endif; ?>
        <?php if (!empty($directorsReportDate)): ?><div>Date: <?= htmlspecialchars($directorsReportDate) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
