<?php
$sectionTitles = getDirectorsReportSectionDefinitions();
?>

<div class="directors-report-preview">
    <h2>Directors' Report</h2>
    <p>To,<br>The Members of <?= htmlspecialchars($companyName) ?></p>

    <?php $counter = 1; foreach ($sectionTitles as $key => $title): ?>
        <h3><?= $counter ?>. <?= htmlspecialchars($title) ?></h3>
        <div class="report-section-text"><?= nl2br(htmlspecialchars((string) ($sections[$key] ?? ''))) ?></div>
        <?php $counter++; ?>
    <?php endforeach; ?>

    <div style="margin-top:36px;">
        <strong>For and on behalf of the Board of Directors</strong>
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
</div>
