<?php
require_once '../includes/functions.php';
requireAdmin();
require_once '../includes/admin_layout.php';

$db = getDB();
$today = date('Y-m-d');
$viewMonth = $_GET['month'] ?? date('Y-m');
$monthStart = $viewMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// All subscriptions for this month view
$subs = $db->prepare("
    SELECT s.*, sh.name as shop_name, sh.phone as shop_phone
    FROM subscriptions s
    JOIN shops sh ON sh.id = s.shop_id
    WHERE (s.start_date <= ? AND s.end_date >= ?)
       OR DATE_FORMAT(s.end_date, '%Y-%m') = ?
       OR DATE_FORMAT(s.start_date, '%Y-%m') = ?
    ORDER BY s.end_date ASC
");
$subs->execute([$monthEnd, $monthStart, $viewMonth, $viewMonth]);
$subs = $subs->fetchAll();

// Expiring within 7 days
$expiring7 = $db->query("
    SELECT s.*, sh.name as shop_name, sh.phone as shop_phone, sh.email
    FROM subscriptions s JOIN shops sh ON sh.id=s.shop_id
    WHERE s.status='active' AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY s.end_date ASC
")->fetchAll();

// Expired (not renewed)
$expired = $db->query("
    SELECT s.*, sh.name as shop_name
    FROM subscriptions s JOIN shops sh ON sh.id=s.shop_id
    WHERE (s.status='expired' OR (s.status='active' AND s.end_date < CURDATE()))
    ORDER BY s.end_date DESC LIMIT 10
")->fetchAll();

$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

adminHeader('Subscription Calendar', 'sub_calendar');
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-calendar3 me-2 text-primary"></i>Subscription Calendar</h1>
        <p class="page-subtitle">Track subscription renewals and expirations</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/subscriptions.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Subscription</a>
</div>

<!-- Expiring Soon Alert -->
<?php if (!empty($expiring7)): ?>
<div class="alert alert-warning rounded-3 mb-4">
    <h6 class="fw-bold mb-2"><i class="bi bi-bell-fill me-2"></i><?= count($expiring7) ?> Subscription<?= count($expiring7)>1?'s':'' ?> Expiring in 7 Days!</h6>
    <div class="row g-2">
        <?php foreach ($expiring7 as $e):
            $days = (int)((strtotime($e['end_date']) - time()) / 86400);
        ?>
        <div class="col-12 col-md-6">
            <div class="d-flex align-items-center justify-content-between p-2 rounded-2 gap-2" style="background:rgba(255,255,255,.05);">
                <div>
                    <div class="fw-semibold small"><?= htmlspecialchars($e['shop_name']) ?></div>
                    <div style="font-size:0.72rem;color:var(--text2,#8eb8c4);">Expires: <?= date('d M Y', strtotime($e['end_date'])) ?></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark"><?= $days ?> day<?= $days!=1?'s':'' ?></span>
                    <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $e['shop_id'] ?>" class="btn btn-xs btn-warning" style="padding:.2rem .5rem;font-size:.72rem;white-space:nowrap;">Renew</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Calendar -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <a href="<?= BASE_URL ?>?month=<?= $prevMonth ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                <span class="fw-bold"><?= date('F Y', strtotime($monthStart)) ?></span>
                <a href="<?= BASE_URL ?>?month=<?= $nextMonth ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            </div>
            <div class="card-body p-3">
                <!-- Calendar grid -->
                <?php
                $firstDay = date('N', strtotime($monthStart)); // 1=Mon, 7=Sun
                $daysInMonth = date('t', strtotime($monthStart));
                $dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                // Map subs by end_date
                $subsByDate = [];
                foreach ($subs as $s) {
                    $endDate = $s['end_date'];
                    if (strstr($endDate, $viewMonth)) {
                        $subsByDate[$endDate][] = $s;
                    }
                }
                ?>
                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;">
                    <?php foreach ($dayNames as $d): ?>
                    <div class="text-center text-muted fw-bold py-1" style="font-size:.75rem;"><?= $d ?></div>
                    <?php endforeach; ?>

                    <?php for ($b = 1; $b < $firstDay; $b++): ?>
                    <div></div>
                    <?php endfor; ?>

                    <?php for ($day = 1; $day <= $daysInMonth; $day++):
                        $dateStr = $viewMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                        $isToday = $dateStr === $today;
                        $daySubs = $subsByDate[$dateStr] ?? [];
                    ?>
                    <div class="text-center p-1 rounded-2 position-relative" style="min-height:52px;background:<?= $isToday?'#6C63FF':'#f8f9fa' ?>;cursor:<?= !empty($daySubs)?'pointer':'default' ?>;"
                         <?= !empty($daySubs) ? "onclick=\"showDaySubs('" . $dateStr . "', " . htmlspecialchars(json_encode(array_map(fn($s)=>['name'=>$s['shop_name'],'status'=>$s['status'],'end_date'=>$s['end_date'],'shop_id'=>$s['shop_id']], $daySubs))) . ")\"" : '' ?>>
                        <div class="fw-bold" style="font-size:.8rem;color:<?= $isToday?'white':'#333' ?>;"><?= $day ?></div>
                        <?php foreach (array_slice($daySubs, 0, 2) as $ds):
                            $isExpiring = $ds['end_date'] < $today;
                            $dot = $isExpiring ? '#ea5455' : '#ff9f43';
                        ?>
                        <div style="width:6px;height:6px;background:<?= $dot ?>;border-radius:50%;display:inline-block;margin:0 1px;"></div>
                        <?php endforeach; ?>
                        <?php if (count($daySubs) > 2): ?>
                        <div style="font-size:.6rem;color:var(--text2,#8eb8c4);">+<?= count($daySubs)-2 ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Legend -->
                <div class="mt-3 d-flex gap-3 flex-wrap justify-content-center" style="font-size:.78rem;">
                    <span><span style="display:inline-block;width:10px;height:10px;background:#ff9f43;border-radius:50%;margin-right:4px;"></span>Active Expiry</span>
                    <span><span style="display:inline-block;width:10px;height:10px;background:#ea5455;border-radius:50%;margin-right:4px;"></span>Expired</span>
                    <span><span style="display:inline-block;width:10px;height:10px;background:#6C63FF;border-radius:50%;margin-right:4px;"></span>Today</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Expired List -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-x-circle me-2 text-danger"></i>Expired Subscriptions</span>
                <a href="<?= BASE_URL ?>/admin/subscriptions.php" class="btn btn-xs btn-outline-danger" style="padding:.2rem .5rem;font-size:.72rem;">Renew All</a>
            </div>
            <div class="card-body p-2" style="overflow-y:auto;max-height:400px;">
                <?php if (empty($expired)): ?>
                <div class="text-center text-muted py-4"><i class="bi bi-check-circle-fill text-success fs-3 d-block mb-2"></i>No expired subscriptions!</div>
                <?php else: ?>
                <?php foreach ($expired as $exp):
                    $expiredDays = (int)((time() - strtotime($exp['end_date'])) / 86400);
                ?>
                <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded-2" style="background:#fff5f5;border-left:3px solid #ea5455;">
                    <div>
                        <div class="fw-semibold small"><?= htmlspecialchars($exp['shop_name']) ?></div>
                        <div style="font-size:.7rem;color:var(--text2,#8eb8c4);"><?= date('d M Y', strtotime($exp['end_date'])) ?> (<?= $expiredDays ?>d ago)</div>
                    </div>
                    <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=<?= $exp['shop_id'] ?>" class="btn btn-xs btn-danger" style="padding:.2rem .5rem;font-size:.7rem;white-space:nowrap;">Renew</a>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Day Modal -->
<div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="dayModalTitle">Subscriptions</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3" id="dayModalBody"></div>
        </div>
    </div>
</div>

<script>
function showDaySubs(date, subs) {
    document.getElementById('dayModalTitle').textContent = 'Expiring: ' + date;
    let html = '';
    subs.forEach(s => {
        const expired = s.end_date < '<?= $today ?>';
        html += `<div class="d-flex justify-content-between align-items-center mb-2 p-2 rounded-2" style="background:${expired?'#fff5f5':'#fff8e8'};">
            <div>
                <div class="fw-semibold small">${s.name}</div>
                <span class="badge ${expired?'bg-danger':'bg-warning text-dark'}" style="font-size:.65rem;">${expired?'Expired':'Active'}</span>
            </div>
            <a href="<?= BASE_URL ?>/admin/subscriptions.php?shop_id=${s.shop_id}" class="btn btn-xs btn-${expired?'danger':'warning'}" style="padding:.2rem .5rem;font-size:.7rem;">Renew</a>
        </div>`;
    });
    document.getElementById('dayModalBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('dayModal')).show();
}
</script>
<?php adminFooter(); ?>
