<?php
/**
 * Export Document Template Generator
 * Auto-fills a printable HTML export document (Commercial Invoice / Proforma)
 * with farmer, buyer, and product details.
 * Accessible by admin and the farmer who owns the export request.
 */
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';

$user_id   = $_SESSION['user_id']   ?? 0;
$user_role = $_SESSION['role']      ?? '';
$export_id = (int)($_GET['export_id'] ?? 0);
$doc_type  = $_GET['type'] ?? 'commercial_invoice'; // commercial_invoice | proforma | packing_list

if (!$user_id) {
    http_response_code(401);
    die("Not authenticated");
}

if (!$export_id) {
    http_response_code(400);
    die("Missing export_id");
}

try {
    $pdo = getDBConnection();

    // Fetch full export request details
    $stmt = $pdo->prepare("
        SELECT 
            er.*,
            p.product_name, p.category, p.unit as product_unit, p.image_url,
            buyer.full_name  as buyer_name,  buyer.email  as buyer_email,
            buyer.phone      as buyer_phone, buyer.address as buyer_address, buyer.country as buyer_country,
            farmer.full_name as farmer_name, farmer.email as farmer_email,
            farmer.phone     as farmer_phone, farmer.address as farmer_address
        FROM export_requests er
        LEFT JOIN products p    ON er.product_id   = p.id
        LEFT JOIN users buyer   ON er.customer_id  = buyer.id
        LEFT JOIN users farmer  ON er.farmer_id    = farmer.id
        WHERE er.id = ?
    ");
    $stmt->execute([$export_id]);
    $exp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exp) { http_response_code(404); die("Export request not found"); }

    // Access control
    if ($user_role !== 'admin' && $exp['farmer_id'] != $user_id && $exp['customer_id'] != $user_id) {
        http_response_code(403); die("Access denied");
    }

    // Format helpers
    $date      = date('d M Y');
    $ref       = 'COF-EXP-' . str_pad($export_id, 6, '0', STR_PAD_LEFT);
    $qty       = number_format($exp['quantity'], 2);
    $unit      = strtoupper($exp['unit'] ?? 'KG');
    $offeredPriceRaw = $exp['offered_price'];
    $currency  = $exp['currency_code'] ?? 'INR';
    $totalRaw  = $offeredPriceRaw ? ($exp['quantity'] * $offeredPriceRaw) : 0;
    $price     = $offeredPriceRaw ? number_format($offeredPriceRaw, 2) : 'TBD';
    $total     = $offeredPriceRaw ? number_format($totalRaw, 2) : 'TBD';
    $mode      = ucfirst($exp['preferred_shipping_mode']);
    $port      = $exp['shipping_port'] ?? 'Cochin Port / Nearest Port';
    $payment   = strtoupper($exp['payment_terms'] ?? 'ADVANCE');

    $docTitles = [
        'commercial_invoice' => 'Commercial Invoice',
        'proforma'           => 'Proforma Invoice',
        'packing_list'       => 'Packing List',
    ];
    $docTitle = $docTitles[$doc_type] ?? 'Export Document';

    // Send as HTML (printable page)
    header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($docTitle) ?> — <?= $ref ?> | Legacy of Spices</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Outfit', sans-serif; background: #f0f4f8; color: #1e293b; }
        .page { max-width: 820px; margin: 32px auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,0.12); }
        .header { background: linear-gradient(135deg, #FF7E21 0%, #c2410c 100%); padding: 36px 40px; color: white; display: flex; justify-content: space-between; align-items: flex-start; }
        .header-brand h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; }
        .header-brand p { font-size: 11px; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.12em; margin-top: 3px; }
        .header-meta { text-align: right; }
        .header-meta .doc-type { font-size: 18px; font-weight: 700; }
        .header-meta .ref { font-size: 12px; opacity: 0.85; margin-top: 4px; }
        .header-meta .doc-date { font-size: 12px; opacity: 0.7; margin-top: 2px; }
        .body { padding: 36px 40px; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; margin-bottom: 28px; }
        .party-box { background: #f8fafc; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; }
        .party-box .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #94a3b8; margin-bottom: 10px; }
        .party-box .name { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .party-box .detail { font-size: 12px; color: #64748b; line-height: 1.7; }
        .section-title { font-size: 12px; font-weight: 700; color: #FF7E21; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        thead tr { background: #FF7E21; }
        thead th { padding: 12px 14px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: white; text-align: left; }
        thead th:last-child { text-align: right; }
        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody td { padding: 14px; font-size: 13px; }
        tbody td:last-child { text-align: right; font-weight: 700; }
        tfoot tr { background: #fff7ed; }
        tfoot td { padding: 14px; font-size: 14px; font-weight: 700; }
        tfoot td:last-child { text-align: right; color: #FF7E21; font-size: 16px; }
        .terms { background: #f8fafc; border-radius: 12px; padding: 20px; margin-bottom: 24px; border: 1px solid #e2e8f0; }
        .terms p { font-size: 12px; color: #64748b; line-height: 1.7; }
        .sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 28px; }
        .sig-box .sig-line { border-bottom: 2px solid #cbd5e1; height: 48px; margin-bottom: 8px; }
        .sig-box p { font-size: 11px; color: #94a3b8; }
        .status-chip { display: inline-block; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; background: #fff7ed; color: #FF7E21; border: 1.5px solid #fed7aa; margin-bottom: 20px; }
        .cert-note { background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
        .cert-note p { font-size: 12px; color: #065f46; line-height: 1.7; }
        .print-btn { position: fixed; bottom: 24px; right: 24px; background: #FF7E21; color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 8px 24px rgba(255,126,33,0.35); font-family: 'Outfit', sans-serif; }
        .print-btn:hover { background: #c2410c; }
        @media print {
            body { background: white; }
            .page { box-shadow: none; border-radius: 0; margin: 0; }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-brand">
            <h1>🌶️ Legacy of Spices</h1>
            <p>International Spice Exporters · India</p>
        </div>
        <div class="header-meta">
            <div class="doc-type"><?= htmlspecialchars($docTitle) ?></div>
            <div class="ref">Ref: <?= $ref ?></div>
            <div class="doc-date">Date: <?= $date ?></div>
        </div>
    </div>

    <div class="body">
        <div class="status-chip">Status: <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $exp['status']))) ?></div>

        <div class="two-col">
            <div class="party-box">
                <div class="label">🇮🇳 Exporter (Seller)</div>
                <div class="name"><?= htmlspecialchars($exp['farmer_name']) ?></div>
                <div class="detail">
                    <?= htmlspecialchars($exp['farmer_address'] ?? 'India') ?><br>
                    📧 <?= htmlspecialchars($exp['farmer_email']) ?><br>
                    📞 <?= htmlspecialchars($exp['farmer_phone'] ?? '—') ?>
                </div>
            </div>
            <div class="party-box">
                <div class="label">🌍 Importer (Buyer)</div>
                <div class="name"><?= htmlspecialchars($exp['buyer_name']) ?></div>
                <div class="detail">
                    Business: <?= htmlspecialchars($exp['business_name'] ?? '—') ?><br>
                    <?= htmlspecialchars($exp['buyer_address'] ?? $exp['buyer_country'] ?? '—') ?><br>
                    📧 <?= htmlspecialchars($exp['buyer_email']) ?><br>
                    📞 <?= htmlspecialchars($exp['buyer_phone'] ?? '—') ?>
                </div>
            </div>
        </div>

        <!-- Shipment Details -->
        <div class="section-title">📦 Shipment Details</div>
        <table>
            <thead>
                <tr>
                    <th>Description of Goods</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Unit</th>
                    <th>Unit Price (<?= htmlspecialchars($currency) ?>)</th>
                    <th>Total (<?= htmlspecialchars($currency) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?= htmlspecialchars($exp['product_name']) ?></strong><br>
                        <span style="font-size:11px;color:#64748b;">Country of Origin: India</span>
                    </td>
                    <td><?= htmlspecialchars($exp['category'] ?? '—') ?></td>
                    <td><?= $qty ?></td>
                    <td><?= $unit ?></td>
                    <td><?= $price ?></td>
                    <td><?= $total ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">Grand Total</td>
                    <td><?= $currency ?> <?= $total ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Logistics Info -->
        <div class="section-title">🚢 Logistics & Payment</div>
        <div class="two-col">
            <div class="party-box">
                <div class="label">Shipping Details</div>
                <div class="detail">
                    Mode: <strong><?= $mode ?> Freight</strong><br>
                    Port: <?= htmlspecialchars($port) ?><br>
                    Destination: <?= htmlspecialchars($exp['target_country']) ?><br>
                    <?php if ($exp['packaging_requirements']): ?>
                    Packaging: <?= htmlspecialchars($exp['packaging_requirements']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="party-box">
                <div class="label">Payment & Compliance</div>
                <div class="detail">
                    Payment Terms: <strong><?= $payment ?></strong><br>
                    Currency: <?= htmlspecialchars($currency) ?><br>
                    Organic Cert: <?= $exp['requires_organic_cert'] ? '✅ Required' : '❌ Not Required' ?><br>
                    Phytosanitary: <?= $exp['requires_phytosanitary'] ? '✅ Required' : '❌ Not Required' ?><br>
                    Quality Test: <?= $exp['requires_quality_test'] ? '✅ Required' : '❌ Not Required' ?>
                </div>
            </div>
        </div>

        <?php if ($exp['special_notes'] || $exp['farmer_notes'] || $exp['admin_notes']): ?>
        <div class="cert-note">
            <p>
                <?php if ($exp['special_notes']): ?><strong>Buyer's Notes:</strong> <?= htmlspecialchars($exp['special_notes']) ?><br><?php endif; ?>
                <?php if ($exp['farmer_notes']): ?><strong>Farmer's Notes:</strong> <?= htmlspecialchars($exp['farmer_notes']) ?><br><?php endif; ?>
                <?php if ($exp['admin_notes']): ?><strong>Admin Notes:</strong> <?= htmlspecialchars($exp['admin_notes']) ?><?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Terms -->
        <div class="terms">
            <div class="section-title">📋 Terms & Conditions</div>
            <p>
                1. Goods are sold as per the agreed specifications. Quality inspected at origin.<br>
                2. All disputes subject to jurisdiction of Indian courts.<br>
                3. Force majeure clauses apply as per standard international trade terms (INCOTERMS 2020).<br>
                4. Payment must be received / LC opened before goods are dispatched (unless otherwise agreed).<br>
                5. This document is computer-generated and valid without a physical signature unless required.
            </p>
        </div>

        <!-- Signatures -->
        <div class="sig-grid">
            <div class="sig-box">
                <div class="sig-line"></div>
                <p>Exporter's Authorized Signature<br><?= htmlspecialchars($exp['farmer_name']) ?> · Farmer / Exporter</p>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <p>Importer's Signature & Stamp<br><?= htmlspecialchars($exp['buyer_name']) ?> · Buyer / Importer</p>
            </div>
        </div>
    </div>
</div>

<button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
</body>
</html>
<?php
} catch (PDOException $e) {
    error_log("Export template error: " . $e->getMessage());
    http_response_code(500);
    echo "<h2>Error generating document. Please try again.</h2>";
}
?>

