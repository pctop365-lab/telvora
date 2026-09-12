<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

function invoiceFixtureCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$source = file_get_contents($root . '/generate_invoice_pdf.php');
invoiceFixtureCheck(is_string($source), 'invoice generator is unavailable');
invoiceFixtureCheck(str_contains($source, '<td class="label">Почта</td>'), 'combined email label is missing');
invoiceFixtureCheck(str_contains($source, 'mailto:' . "' . h(\$sellerOrdersEmail)"), 'orders email link is missing');
invoiceFixtureCheck(str_contains($source, 'mailto:' . "' . h(\$sellerSupportEmail)"), 'support email link is missing');
invoiceFixtureCheck(!str_contains($source, 'Почта поддержки'), 'separate support email row remains');
invoiceFixtureCheck(!str_contains($source, 'Багратионовский'), 'fake seller address remains');

$contacts = json_decode((string)file_get_contents($root . '/public_contacts.json'), true, flags: JSON_THROW_ON_ERROR);
$autoload = getenv('TELVORA_TEST_AUTOLOAD');
invoiceFixtureCheck(is_string($autoload) && is_file($autoload), 'set TELVORA_TEST_AUTOLOAD to the local Dompdf autoloader');
require $autoload;

$emailLine = htmlspecialchars($contacts['ordersEmail'] . ' / ' . $contacts['supportEmail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html = '<!doctype html><html lang="ru"><meta charset="UTF-8"><style>
@page{margin:18mm 15mm}body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}table{width:100%;border-collapse:collapse}.info{margin-top:12px}.info td,.products td,.products th{border:1px solid #222;padding:7px;vertical-align:top}.label{width:32%;font-weight:bold;background:#f2f2f2}.products{margin-top:16px}.total{margin-top:10px}.signatures{margin-top:25px}.signatures td{width:50%;padding-right:20px}.line{margin-top:30px;border-bottom:1px solid #222;height:20px}
</style><body><h1 style="text-align:center">ТОВАРНАЯ НАКЛАДНАЯ</h1>
<table class="info"><tr><td class="label">Продавец / ИНН / Реквизиты</td><td>' . htmlspecialchars($contacts['sellerShortName'] . ' · ИНН ' . $contacts['inn'] . ' · ОГРНИП ' . $contacts['ogrnip']) . '<br>' . htmlspecialchars($contacts['bankName'] . ' · р/с ' . $contacts['bankAccount'] . ' · БИК ' . $contacts['bik'] . ' · к/с ' . $contacts['correspondentAccount']) . '</td></tr><tr><td class="label">Телефон</td><td>' . htmlspecialchars($contacts['phoneDisplay']) . '</td></tr><tr><td class="label">Почта</td><td>' . $emailLine . '</td></tr><tr><td class="label">НДС</td><td>' . htmlspecialchars($contacts['vatNotice']) . '</td></tr></table>
<table class="products"><tr><th>Товар</th><th>Количество</th><th>Цена</th><th>Сумма</th></tr><tr><td>Тестовый телевизор TELVORA</td><td>1</td><td>100 000 ₽</td><td>100 000 ₽</td></tr><tr><td colspan="3">Доставка</td><td>2 000 ₽</td></tr></table>
<table class="total"><tr><td><b>Итого: 102 000 ₽</b></td></tr></table><table class="signatures"><tr><td>Продавец<div class="line"></div></td><td>Покупатель<div class="line"></div></td></tr></table></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdf = $dompdf->output();

invoiceFixtureCheck(str_starts_with($pdf, '%PDF-'), 'fixture did not render as PDF');
invoiceFixtureCheck(strlen($pdf) > 5000, 'rendered PDF is unexpectedly small');
invoiceFixtureCheck($dompdf->getCanvas()->get_page_count() === 1, 'fixture no longer fits on one page');

echo "invoice_contacts_pdf_fixture_test: PASS (1 page, " . strlen($pdf) . " bytes)\n";
