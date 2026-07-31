<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
$db = getDB();
$today = date('Y-m-d');

// Shop creation date for calendar restriction
$_shopDataDt = $db->prepare("SELECT created_at FROM shops WHERE id=?");
$_shopDataDt->execute([$shopId]);
$_shopDataDt = $_shopDataDt->fetch();
$shopCreatedDate = $_shopDataDt ? date('Y-m-d', strtotime($_shopDataDt['created_at'])) : '2020-01-01';

// Handle target setting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_target') {
    $amount = safeFloat($_POST['target_amount'] ?? 0);
    $date   = sanitize($_POST['target_date'] ?? $today);
    $db->prepare("INSERT INTO daily_targets (shop_id,target_date,target_amount) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount)")
       ->execute([$shopId, $date, $amount]);
    header('Location: ' . BASE_URL . '/shop/daily_target.php?msg=Target+set+successfully&type=success');
    exit;
}

// Today's target
$todayTarget = $db->prepare("SELECT * FROM daily_targets WHERE shop_id=? AND target_date=?");
$todayTarget->execute([$shopId, $today]);
$todayTarget = $todayTarget->fetch();

// Today's actual sales
$todaySales = (float)$db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE shop_id=? AND DATE(sale_date)=?")->execute([$shopId,$today]) ? 0 : 0;
$stToday = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE shop_id=? AND DATE(sale_date)=?");
$stToday->execute([$shopId,$today]); $todaySales = (float)$stToday->fetch()['t'];

// Last 7 days targets
$last7 = $db->prepare("
    SELECT dt.target_date, dt.target_amount,
           COALESCE(SUM(s.grand_total),0) as achieved
    FROM daily_targets dt
    LEFT JOIN sales s ON s.shop_id=? AND DATE(s.sale_date)=dt.target_date
    WHERE dt.shop_id=? AND dt.target_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY dt.target_date
    ORDER BY dt.target_date ASC
");
$last7->execute([$shopId, $shopId]);
$last7 = $last7->fetchAll();

// Current week stats
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekSales = (float)$db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE shop_id=? AND DATE(sale_date)>=?")->execute([$shopId,$weekStart]) ? 0 : 0;
$stWeek = $db->prepare("SELECT COALESCE(SUM(grand_total),0) as t FROM sales WHERE shop_id=? AND DATE(sale_date)>=?");
$stWeek->execute([$shopId,$weekStart]); $weekSales = (float)$stWeek->fetch()['t'];

shopHeader('Daily Target', 'target');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-bullseye me-2 text-primary"></i>Daily Sales Target</h1>
        <p class="page-subtitle">Set and track your daily sales goals</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#setTargetModal">
        <i class="bi bi-plus-circle me-1"></i>Set Today's Target
    </button>
</div>

<!-- Today's Progress -->
<?php if ($todayTarget): ?>
<?php
$targetAmt = (float)$todayTarget['target_amount'];
$achieved  = $todaySales;
$pct = $targetAmt > 0 ? min(100, round($achieved / $targetAmt * 100)) : 0;
$remaining = max(0, $targetAmt - $achieved);
$barColor = $pct >= 100 ? '#28c76f' : ($pct >= 60 ? '#ff9f43' : '#ea5455');
?>
<div class="card mb-4" style="background:linear-gradient(135deg,#6C63FF,#3ECFCF);color:white;border:none;">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div>
                <div class="opacity-75 small mb-1">Today's Target — <?= date('d M Y') ?></div>
                <h2 class="fw-bold mb-0">Rs. <?= number_format($targetAmt,0) ?></h2>
            </div>
            <div class="text-end">
                <div class="opacity-75 small mb-1">Achieved</div>
                <h2 class="fw-bold mb-0">Rs. <?= number_format($achieved,0) ?></h2>
            </div>
        </div>
        <!-- Progress Circle / Bar -->
        <div class="mb-2">
            <div class="d-flex justify-content-between mb-1" style="font-size:.85rem;">
                <span><?= $pct ?>% Complete</span>
                <?php if ($pct >= 100): ?>
                <span>🎉 Target Achieved!</span>
                <?php else: ?>
                <span>Rs. <?= number_format($remaining,0) ?> remaining</span>
                <?php endif; ?>
            </div>
            <div class="progress" style="height:14px;background:rgba(255,255,255,.2);border-radius:10px;">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:10px;transition:width .6s ease;"></div>
            </div>
        </div>
        <?php if ($pct >= 100): ?>
        <div class="alert mt-2 mb-0 py-2 rounded-2" style="background:rgba(255,255,255,.15);border:none;color:white;">
            <i class="bi bi-trophy-fill me-1 text-warning"></i> <strong>Congratulations!</strong> You've hit your daily target!
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card mb-4" style="border:2px dashed #d0d0d0;">
    <div class="card-body text-center py-4">
        <i class="bi bi-bullseye fs-1 d-block mb-2 text-muted opacity-25"></i>
        <h6 class="text-muted">No target set for today</h6>
        <p class="text-muted small mb-3">Set a daily sales target to track your progress</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#setTargetModal">
            <i class="bi bi-plus-circle me-1"></i>Set Today's Target
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div class="stat-card-icon"><i class="bi bi-currency-rupee"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($todaySales,0) ?></div>
            <div class="stat-card-label">Today's Sales</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div class="stat-card-icon"><i class="bi bi-calendar-week"></i></div>
            <div class="stat-card-value">Rs.<?= number_format($weekSales,0) ?></div>
            <div class="stat-card-label">This Week</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div class="stat-card-icon"><i class="bi bi-check-circle"></i></div>
            <?php $achieved7 = count(array_filter($last7, fn($r) => $r['achieved'] >= $r['target_amount'] && $r['target_amount'] > 0)); ?>
            <div class="stat-card-value"><?= $achieved7 ?> / <?= count($last7) ?></div>
            <div class="stat-card-label">Targets Hit (7d)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div class="stat-card-icon"><i class="bi bi-graph-up"></i></div>
            <?php $avgTarget = count($last7) > 0 ? array_sum(array_column($last7,'target_amount')) / count($last7) : 0; ?>
            <div class="stat-card-value">Rs.<?= number_format($avgTarget,0) ?></div>
            <div class="stat-card-label">Avg Daily Target</div>
        </div>
    </div>
</div>

<!-- Last 7 Days Chart -->
<?php if (!empty($last7)): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bar-chart me-2 text-primary"></i>Target vs Achieved (Last 7 Days)</div>
    <div class="card-body p-3">
        <canvas id="targetChart" height="120"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- History Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list me-2"></i>Target History</span>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#setTargetModal">
            <i class="bi bi-plus-circle me-1"></i>Set Target
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Date</th><th>Target (Rs)</th><th>Achieved (Rs)</th><th>% Done</th><th>Status</th></tr></thead>
            <tbody>
                <?php
                $allTargets = $db->prepare("
                    SELECT dt.target_date, dt.target_amount,
                           COALESCE(SUM(s.grand_total),0) as achieved
                    FROM daily_targets dt
                    LEFT JOIN sales s ON s.shop_id=? AND DATE(s.sale_date)=dt.target_date
                    WHERE dt.shop_id=?
                    GROUP BY dt.target_date ORDER BY dt.target_date DESC LIMIT 30
                ");
                $allTargets->execute([$shopId, $shopId]);
                foreach ($allTargets->fetchAll() as $row):
                    $p = $row['target_amount'] > 0 ? min(100, round($row['achieved']/$row['target_amount']*100)) : 0;
                ?>
                <tr class="<?= $p>=100?'table-success':($p>=50?'':'table-danger') ?>">
                    <td><?= date('d M Y', strtotime($row['target_date'])) ?></td>
                    <td class="fw-bold">Rs. <?= number_format($row['target_amount'],0) ?></td>
                    <td class="fw-bold text-<?= $p>=100?'success':($p>=50?'warning':'danger') ?>">Rs. <?= number_format($row['achieved'],0) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;">
                                <div class="progress-bar bg-<?= $p>=100?'success':($p>=50?'warning':'danger') ?>" style="width:<?= $p ?>%;"></div>
                            </div>
                            <span style="font-size:.72rem;min-width:35px;"><?= $p ?>%</span>
                        </div>
                    </td>
                    <td>
                        <?php if ($p >= 100): ?>
                        <span class="badge bg-success">✓ Hit</span>
                        <?php elseif ($row['target_date'] === $today): ?>
                        <span class="badge bg-primary">Today</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Missed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Set Target Modal -->
<div class="modal fade" id="setTargetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-bullseye me-2"></i>Set Daily Target</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="set_target">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" class="form-control" name="target_date" value="<?= $today ?>" min="<?= $shopCreatedDate ?>" max="<?= $today ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Target Amount (Rs.)</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold">Rs.</span>
                            <input type="number" class="form-control" name="target_amount" min="0" step="100" required placeholder="e.g. 50000">
                        </div>
                    </div>
                    <?php if ($todayTarget): ?>
                    <div class="alert alert-info py-2 small mb-0">
                        Current target: Rs. <?= number_format($todayTarget['target_amount'],0) ?>. Saving will overwrite.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check me-1"></i>Save Target</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($last7)): ?>
<script>
new Chart(document.getElementById('targetChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($r) => date('d M', strtotime($r['target_date'])), $last7)) ?>,
        datasets: [
            { label: 'Target', data: <?= json_encode(array_column($last7,'target_amount')) ?>, backgroundColor: 'rgba(108,99,255,0.3)', borderColor: '#6C63FF', borderWidth: 2, borderRadius: 6 },
            { label: 'Achieved', data: <?= json_encode(array_column($last7,'achieved')) ?>, backgroundColor: 'rgba(40,199,111,0.7)', borderRadius: 6 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => 'Rs.'+(v>=1000?(v/1000).toFixed(0)+'K':v) }, grid: { color: '#f0f0f0' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php shopFooter(); ?>
