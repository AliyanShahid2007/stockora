<?php
require_once '../includes/functions.php';
requireShop();
require_once '../includes/shop_layout.php';

$shopId = (int)$_SESSION['shop_id'];
requirePremiumFeature($shopId, 'AI Decision Engine');
$db     = getDB();
$db && trackShopFeatureUsage($shopId, 'ai_lab');
$today  = date('Y-m-d');

// ── All active products with full stats ──────────────────────
$prodQ = $db->prepare(
    "SELECT p.id, p.name, p.retail_price, p.company_price, p.stock_quantity,
            p.unit, p.min_stock_alert, c.name AS category_name,
            COALESCE(s30.qty_30d,    0) AS qty_30d,
            COALESCE(s30.profit_30d, 0) AS profit_30d,
            COALESCE(s30.txn_30d,    0) AS txn_30d,
            COALESCE(s30.rev_30d,    0) AS rev_30d,
            COALESCE(s7.qty_7d,      0) AS qty_7d,
            COALESCE(s7.profit_7d,   0) AS profit_7d,
            COALESCE(sall.total_sold, 0) AS total_sold,
            COALESCE(sall.total_profit,0) AS total_profit,
            COALESCE(sall.days_active, 0) AS days_active
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN (
         SELECT si.product_id,
                SUM(si.quantity)     AS qty_30d,
                SUM(si.profit)       AS profit_30d,
                SUM(si.total_price)  AS rev_30d,
                COUNT(DISTINCT s.id) AS txn_30d
         FROM sale_items si JOIN sales s ON s.id=si.sale_id
         WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)
         GROUP BY si.product_id
     ) s30 ON s30.product_id=p.id
     LEFT JOIN (
         SELECT si.product_id,
                SUM(si.quantity) AS qty_7d,
                SUM(si.profit)   AS profit_7d
         FROM sale_items si JOIN sales s ON s.id=si.sale_id
         WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(?,INTERVAL 7 DAY)
         GROUP BY si.product_id
     ) s7 ON s7.product_id=p.id
     LEFT JOIN (
         SELECT si.product_id,
                SUM(si.quantity) AS total_sold,
                SUM(si.profit)   AS total_profit,
                COUNT(DISTINCT DATE(s.sale_date)) AS days_active
         FROM sale_items si JOIN sales s ON s.id=si.sale_id
         WHERE s.shop_id=?
         GROUP BY si.product_id
     ) sall ON sall.product_id=p.id
     WHERE p.shop_id=? AND p.status='active'
     ORDER BY qty_30d DESC, p.name ASC"
);
$prodQ->execute([$shopId,$today,$shopId,$today,$shopId,$shopId]);
$allProducts = $prodQ->fetchAll();

// ── Shop stats ───────────────────────────────────────────────
$statsRow = $db->prepare("SELECT COALESCE(SUM(grand_total),0) rev30, COALESCE(COUNT(*),0) txn30 FROM sales WHERE shop_id=? AND DATE(sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)");
$statsRow->execute([$shopId,$today]);
$shopStats = $statsRow->fetch();
$profitRow = $db->prepare("SELECT COALESCE(SUM(si.profit),0) prof30 FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.shop_id=? AND DATE(s.sale_date)>=DATE_SUB(?,INTERVAL 30 DAY)");
$profitRow->execute([$shopId,$today]);
$shopStats['prof30'] = (float)$profitRow->fetch()['prof30'];
$expRow = $db->prepare("SELECT COALESCE(SUM(amount),0) exp30 FROM expenses WHERE shop_id=? AND DATE(expense_date)>=DATE_SUB(?,INTERVAL 30 DAY)");
$expRow->execute([$shopId,$today]);
$shopStats['exp30']    = (float)$expRow->fetch()['exp30'];
$shopStats['margin30'] = $shopStats['rev30']>0 ? round($shopStats['prof30']/$shopStats['rev30']*100,1) : 0;

// ── 7-day daily trend ────────────────────────────────────────
$dailySales = [];
for ($i=6;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $r = $db->prepare("SELECT COALESCE(SUM(grand_total),0) s, COALESCE(SUM(si2.profit),0) p FROM sales s2 LEFT JOIN sale_items si2 ON si2.sale_id=s2.id WHERE s2.shop_id=? AND DATE(s2.sale_date)=?");
    $r->execute([$shopId,$d]);
    $row = $r->fetch();
    $dailySales[] = ['date'=>date('D',strtotime($d)),'s'=>(float)$row['s'],'p'=>(float)$row['p']];
}

// ── Price recommendations ────────────────────────────────────
$priceRecs = [];
foreach ($allProducts as $p) {
    $retail=$p['retail_price']; $cost=$p['company_price']; $qty30=(int)$p['qty_30d'];
    $margin=$retail>0?(($retail-$cost)/$retail*100):0;
    $tm=25; if($qty30>=20)$tm=20; if($qty30===0)$tm=30;
    $rec=$cost>0?round($cost/(1-$tm/100)):$retail; $rec=round($rec/5)*5;
    $diff=$rec-$retail; $dPct=$retail>0?round($diff/$retail*100,1):0;
    $act=abs($dPct)<2?'keep':($diff>0?'raise':'lower');
    $priceRecs[]=['name'=>$p['name'],'cost'=>$cost,'current'=>$retail,'rec'=>$rec,'margin'=>round($margin,1),'rec_margin'=>$tm,'diff'=>$diff,'diffPct'=>$dPct,'action'=>$act,'extra30'=>$qty30>0?round($diff*$qty30):0,'qty30'=>$qty30,'unit'=>$p['unit']?:'pcs'];
}
usort($priceRecs,fn($a,$b)=>$b['diff']<=>$a['diff']);
$raiseCount=count(array_filter($priceRecs,fn($r)=>$r['action']==='raise'));
$lowerCount=count(array_filter($priceRecs,fn($r)=>$r['action']==='lower'));
$keepCount =count(array_filter($priceRecs,fn($r)=>$r['action']==='keep'));
$totalExtra=array_sum(array_column(array_filter($priceRecs,fn($r)=>$r['extra30']>0&&$r['action']==='raise'),'extra30'));

// ── Demand data ──────────────────────────────────────────────
$demandData=[];
foreach($allProducts as $p){
    $qty30=(int)$p['qty_30d'];$qty7=(int)$p['qty_7d'];$stock=(int)$p['stock_quantity'];
    $a30=$qty30/30;$a7=$qty7/7;$tr=$a30>0?($a7/$a30):($qty7>0?3:0);
    if($tr>=1.5){$fc='high';$fq=round($a7*7*1.2);$fl='High Demand Expected';$fc2='#28c76f';$fi='rocket-takeoff-fill';}
    elseif($tr>=0.8&&$qty30>0){$fc='stable';$fq=round($a30*7);$fl='Stable Demand';$fc2='#6C63FF';$fi='dash-circle-fill';}
    elseif($qty30>0&&$tr<0.5){$fc='decreasing';$fq=round($a30*7*0.6);$fl='Demand Decreasing';$fc2='#ff9f43';$fi='arrow-down-circle-fill';}
    elseif($qty30===0&&$stock>0){$fc='dead';$fq=0;$fl='No Demand Detected';$fc2='#ea5455';$fi='x-octagon-fill';}
    else continue;
    $sd=$a7>0?round($stock/$a7):999;
    $demandData[]=['name'=>$p['name'],'forecast'=>$fc,'forecastQty'=>$fq,'forecastLabel'=>$fl,'color'=>$fc2,'icon'=>$fi,'qty7'=>$qty7,'qty30'=>$qty30,'stock'=>$stock,'stockDays'=>$sd,'stockAlert'=>($sd<7?'danger':($sd<14?'warning':'ok')),'unit'=>$p['unit']?:'pcs','trendRatio'=>round($tr,1),'profit30'=>round((float)$p['profit_30d']),'retail'=>(float)$p['retail_price']];
}
$dOrder=['high'=>0,'stable'=>1,'decreasing'=>2,'dead'=>3];
usort($demandData,fn($a,$b)=>($dOrder[$a['forecast']]??4)<=>($dOrder[$b['forecast']]??4));
$highCount=count(array_filter($demandData,fn($d)=>$d['forecast']==='high'));
$decCount =count(array_filter($demandData,fn($d)=>$d['forecast']==='decreasing'));
$deadCount=count(array_filter($demandData,fn($d)=>$d['forecast']==='dead'));

// ── Loss alerts ──────────────────────────────────────────────
$lossAlerts=[];
foreach($allProducts as $p){
    $retail=(float)$p['retail_price'];$cost=(float)$p['company_price'];$stock=(int)$p['stock_quantity'];$qty30=(int)$p['qty_30d'];$profit30=(float)$p['profit_30d'];
    $margin=$retail>0?(($retail-$cost)/$retail*100):0;$alerts=[];
    if($cost>$retail&&$retail>0) $alerts[]=['severity'=>'critical','icon'=>'exclamation-octagon-fill','color'=>'#ea5455','msg'=>'Selling below cost! Every sale loses Rs.'.number_format(abs($retail-$cost)).'.','action'=>'Raise price immediately: Rs.'.number_format($cost*1.2)];
    if($margin>0&&$margin<8&&$cost>0) $alerts[]=['severity'=>'high','icon'=>'exclamation-triangle-fill','color'=>'#ea5455','msg'=>'Margin only '.round($margin,1).'% — barely covering costs.','action'=>'Suggested price: Rs.'.number_format(round($cost/0.85/5)*5)];
    if($qty30===0&&$stock>10) $alerts[]=['severity'=>'high','icon'=>'archive-fill','color'=>'#ff9f43','msg'=>'Dead stock! Rs.'.number_format($stock*$cost).' capital locked — zero sales in 30 days.','action'=>'Apply 10-15% discount or create a bundle deal'];
    if($stock<=0&&$qty30>5) $alerts[]=['severity'=>'high','icon'=>'box-seam','color'=>'#ff9f43','msg'=>'Out of stock! Missing approx Rs.'.number_format(round(($qty30/30)*$retail)).'/day in revenue.','action'=>'Reorder today — minimum '.max(20,(int)$p['min_stock_alert']*4).' units'];
    if($profit30<0) $alerts[]=['severity'=>'critical','icon'=>'graph-down-arrow','color'=>'#ea5455','msg'=>'Rs.'.number_format(abs(round($profit30))).' NET LOSS in the last 30 days!','action'=>'Fix pricing immediately — review cost & selling price'];
    if(!empty($alerts)) $lossAlerts[]=['name'=>$p['name'],'alerts'=>$alerts,'stock'=>$stock,'margin'=>round($margin,1),'unit'=>$p['unit']?:'pcs','profit30'=>$profit30,'cost'=>$cost,'retail'=>$retail];
}
usort($lossAlerts,function($a,$b){$sa=in_array('critical',array_column($a['alerts'],'severity'))?0:1;$sb=in_array('critical',array_column($b['alerts'],'severity'))?0:1;return $sa<=>$sb;});
$critCount=0;
foreach($lossAlerts as $la) foreach($la['alerts'] as $al) if($al['severity']==='critical'){$critCount++;break;}

// ── AI Classification for overview ──────────────────────────
function classifyProduct($p) {
    $retail=(float)$p['retail_price'];$cost=(float)$p['company_price'];
    $margin=$retail>0?(($retail-$cost)/$retail*100):0;
    $qty30=(int)$p['qty_30d'];$qty7=(int)$p['qty_7d'];$stock=(int)$p['stock_quantity'];
    $avg30=$qty30/30;$avg7=$qty7/7;
    $velRatio=$avg30>0?($avg7/$avg30):($qty7>0?2:0);
    $score=0;
    $score+=($margin>=40?30:($margin>=30?25:($margin>=20?18:($margin>=12?10:($margin>=5?4:0)))));
    $score+=($qty30>=40?25:($qty30>=20?20:($qty30>=8?13:($qty30>=2?7:($qty30>=1?3:0)))));
    $score+=($stock<=0?0:($stock<=$p['min_stock_alert']?10:20));
    $score+=($velRatio>=1.5?15:($velRatio>=0.8?10:($velRatio>=0.5?5:0)));
    if($cost>$retail&&$retail>0) $score-=20;
    $score=min(100,max(1,(int)$score));
    if($score>=88) return ['label'=>'Best Seller','color'=>'#28c76f','icon'=>'trophy-fill','score'=>$score];
    if($score>=72) return ['label'=>'Growth Product','color'=>'#3ECFCF','icon'=>'graph-up-arrow','score'=>$score];
    if($score>=55) return ['label'=>'Stable Product','color'=>'#6C63FF','icon'=>'check-circle-fill','score'=>$score];
    if($score>=38) return ['label'=>'Average Product','color'=>'#ff9f43','icon'=>'dash-circle','score'=>$score];
    if($score>=22) return ['label'=>'Risk Product','color'=>'#ea5455','icon'=>'exclamation-triangle-fill','score'=>$score];
    return ['label'=>'Low Performer','color'=>'#8b0000','icon'=>'x-circle-fill','score'=>$score];
}

// ── Business Summary stats ───────────────────────────────────
$bestSellers=0;$growthProds=0;$stableProds=0;$avgProds=0;$riskProds=0;$lowProds=0;
$strongNextMonth=[];$weakProds=[];
foreach($allProducts as $p){
    $cls=classifyProduct($p);
    if($cls['label']==='Best Seller')$bestSellers++;
    elseif($cls['label']==='Growth Product')$growthProds++;
    elseif($cls['label']==='Stable Product')$stableProds++;
    elseif($cls['label']==='Average Product')$avgProds++;
    elseif($cls['label']==='Risk Product')$riskProds++;
    else $lowProds++;
    if($cls['score']>=72&&(int)$p['qty_30d']>0) $strongNextMonth[]=$p['name'];
    if($cls['score']<38) $weakProds[]=$p['name'];
}

// ── Auto Tags ────────────────────────────────────────────────
function computeTags(array $p): array {
    $tags=[];
    $margin=$p['retail_price']>0?(($p['retail_price']-$p['company_price'])/$p['retail_price']*100):0;
    if($p['total_sold']>=100)      $tags[]=['label'=>'Best Seller','color'=>'#f59e0b','icon'=>'trophy-fill'];
    elseif($margin>=30)            $tags[]=['label'=>'High Profit','color'=>'#28c76f','icon'=>'graph-up-arrow'];
    elseif($margin<10&&$p['company_price']>0) $tags[]=['label'=>'Low Margin','color'=>'#ea5455','icon'=>'exclamation-triangle-fill'];
    if($p['qty_30d']>=20)          $tags[]=['label'=>'Fast Moving','color'=>'#6C63FF','icon'=>'lightning-charge-fill'];
    elseif($p['qty_30d']===0&&$p['stock_quantity']>0) $tags[]=['label'=>'Dead Stock','color'=>'#ff9f43','icon'=>'archive-fill'];
    if($p['stock_quantity']<=0)    $tags[]=['label'=>'Out of Stock','color'=>'#ea5455','icon'=>'x-circle-fill'];
    elseif($p['stock_quantity']<=$p['min_stock_alert']&&$p['min_stock_alert']>0) $tags[]=['label'=>'Low Stock','color'=>'#ff9f43','icon'=>'exclamation-circle-fill'];
    if($p['qty_7d']>($p['qty_30d']/4)&&$p['qty_30d']>0) $tags[]=['label'=>'Trending Up','color'=>'#3ECFCF','icon'=>'rocket-takeoff-fill'];
    if(empty($tags)) $tags[]=['label'=>'Normal','color'=>'#adb5bd','icon'=>'circle'];
    return $tags;
}
$tagGroups=['Best Seller'=>[],'Fast Moving'=>[],'High Profit'=>[],'Trending Up'=>[],'Low Stock'=>[],'Low Margin'=>[],'Dead Stock'=>[],'Out of Stock'=>[],'Normal'=>[]];
foreach($allProducts as $p){
    $tags=computeTags($p);
    $pInfo=['name'=>$p['name'],'retail'=>$p['retail_price'],'cost'=>$p['company_price'],'stock'=>$p['stock_quantity'],'unit'=>$p['unit']?:'pcs','qty30'=>$p['qty_30d'],'tags'=>$tags,'margin'=>$p['retail_price']>0?round(($p['retail_price']-$p['company_price'])/$p['retail_price']*100,1):0];
    $added=false;
    foreach($tags as $t){if(isset($tagGroups[$t['label']])){$tagGroups[$t['label']][]=$pInfo;$added=true;break;}}
    if(!$added) $tagGroups['Normal'][]=$pInfo;
}
$tagColors=['Best Seller'=>'#f59e0b','Fast Moving'=>'#6C63FF','High Profit'=>'#28c76f','Trending Up'=>'#3ECFCF','Low Stock'=>'#ff9f43','Low Margin'=>'#ea5455','Dead Stock'=>'#ff9f43','Out of Stock'=>'#ea5455','Normal'=>'#adb5bd'];
$tagIcons=['Best Seller'=>'trophy-fill','Fast Moving'=>'lightning-charge-fill','High Profit'=>'graph-up-arrow','Trending Up'=>'rocket-takeoff-fill','Low Stock'=>'exclamation-circle-fill','Low Margin'=>'exclamation-triangle-fill','Dead Stock'=>'archive-fill','Out of Stock'=>'x-circle-fill','Normal'=>'circle'];

shopHeader('AI Decision Engine', 'ai_lab');
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   AI DECISION ENGINE — Complete Theme
═══════════════════════════════════════════════════════════════ */
.ai-page-bg{background:#070a12;min-height:100%;}

/* ── Glassmorphism Base ── */
.glass{background:#0f1117;border:1px solid #1e2130;border-radius:20px;}
.glass-strong{background:#141824;border:1px solid #232840;border-radius:20px;}
.glass-card{background:#0c0e1e;border:1px solid rgba(108,99,255,.18);border-radius:20px;padding:1.5rem 1.6rem;position:relative;overflow:hidden;margin-bottom:1.2rem;box-shadow:0 4px 32px rgba(0,0,0,.4);}
.glass-card::before{content:'';position:absolute;top:-80px;right:-80px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(108,99,255,.08),transparent 68%);pointer-events:none;}
.glass-section{background:#0a0d14;border:1px solid #1a1e2e;border-radius:16px;padding:1.2rem 1.3rem;margin-bottom:1rem;}
.glass-tile{background:#111420;border:1px solid #1e2130;border-radius:14px;padding:1rem 1.1rem;transition:all .2s;}
.glass-tile:hover{background:#161b2e;border-color:rgba(108,99,255,.35);transform:translateY(-2px);}

/* ── Tab Nav ── */
.ai-tab-nav{display:flex;gap:.45rem;flex-wrap:wrap;margin-bottom:1.8rem;padding:.55rem;background:#0a0c18;border-radius:16px;border:1px solid #1a1d2e;}
.ai-tab{padding:.52rem 1.1rem;border-radius:12px;font-size:.79rem;font-weight:700;cursor:pointer;border:1.5px solid transparent;color:rgba(255,255,255,.45);background:transparent;transition:all .22s;display:flex;align-items:center;gap:.38rem;white-space:nowrap;}
.ai-tab:hover{border-color:rgba(108,99,255,.3);color:rgba(255,255,255,.8);background:rgba(108,99,255,.07);}
.ai-tab.active{background:linear-gradient(135deg,#6C63FF,#3ECFCF);color:#fff;border-color:transparent;box-shadow:0 4px 18px rgba(108,99,255,.4);}
.ai-section{display:none;animation:fadeUp .28s ease;}
.ai-section.active{display:block;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ── Card Header ── */
.ai-card-header{display:flex;align-items:center;gap:.9rem;margin-bottom:1.4rem;position:relative;z-index:1;}
.ai-icon-box{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
.ai-card-title{color:#fff;font-weight:800;font-size:1.05rem;line-height:1.2;}
.ai-card-sub{color:rgba(255,255,255,.38);font-size:.76rem;margin-top:.15rem;}

/* ── AI Summary Banner ── */
.ai-summary-banner{background:linear-gradient(135deg,#0e0c1e 0%,#0a1020 50%,#080e1c 100%);border:1px solid rgba(108,99,255,.25);border-radius:20px;padding:1.5rem 1.6rem;margin-bottom:1.5rem;position:relative;overflow:hidden;box-shadow:0 4px 32px rgba(108,99,255,.08);}
.ai-summary-banner::before{content:'';position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(108,99,255,.15),transparent 65%);pointer-events:none;}
.ai-summary-banner::after{content:'';position:absolute;bottom:-40px;left:-40px;width:160px;height:160px;border-radius:50%;background:radial-gradient(circle,rgba(62,207,207,.1),transparent 65%);pointer-events:none;}
.summary-insight{background:#111420;border:1px solid #1e2130;border-radius:12px;padding:.65rem .9rem;font-size:.78rem;color:rgba(255,255,255,.7);line-height:1.5;}

/* ── Classification Badges ── */
.ai-class-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .78rem;border-radius:30px;font-size:.72rem;font-weight:800;letter-spacing:.3px;}

/* ── Product Selector ── */
.ai-prod-select{background:#111420;border:1.5px solid #1e2130;border-radius:13px;color:#fff;padding:.78rem 2.5rem .78rem 1rem;font-size:.87rem;width:100%;outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23adb5bd' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:calc(100% - 14px) center;transition:all .2s;}
.ai-prod-select option{background:#12122a;color:#fff;}
.ai-prod-select:focus{border-color:#6C63FF;box-shadow:0 0 0 3px rgba(108,99,255,.18);}

/* ── Run Button ── */
.ai-run-btn{background:linear-gradient(135deg,#6C63FF,#3ECFCF);border:none;border-radius:13px;color:#fff;padding:.78rem 1.8rem;font-size:.86rem;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:.5rem;transition:all .22s;white-space:nowrap;box-shadow:0 4px 18px rgba(108,99,255,.35);}
.ai-run-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 24px rgba(108,99,255,.5);}
.ai-run-btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none;}

/* ── Metric Tiles ── */
.ai-metric-tile{background:#111420;border:1px solid #1e2130;border-radius:14px;padding:1rem 1.1rem;flex:1;min-width:130px;transition:all .2s;}
.ai-metric-tile:hover{background:#161b2e;transform:translateY(-2px);}
.ai-metric-tile .lbl{font-size:.62rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.7px;}
.ai-metric-tile .val{font-size:1.15rem;font-weight:900;margin:.2rem 0 .04rem;}
.ai-metric-tile .sub{font-size:.63rem;color:rgba(255,255,255,.25);}

/* ── Chart Boxes ── */
.pt-chart-box{background:#0a0d14;border:1px solid #1a1e2e;border-radius:16px;padding:1.2rem;height:100%;}
.pt-chart-label{color:rgba(255,255,255,.4);font-size:.69rem;font-weight:700;text-transform:uppercase;letter-spacing:.9px;margin-bottom:.7rem;}

/* ── Verdict ── */
.pt-verdict{border-radius:16px;padding:1.15rem 1.25rem;}

/* ── Suggestions ── */
.pt-sugg{display:flex;align-items:flex-start;gap:.7rem;margin-bottom:.55rem;background:#0c0f1a;border:1px solid #1a1e2e;border-radius:12px;padding:.75rem 1rem;transition:all .2s;}
.pt-sugg:hover{background:#111520;border-color:rgba(108,99,255,.28);}
.pt-sugg-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;}

/* ── Price Optimizer ── */
.price-row{display:flex;align-items:center;gap:.9rem;padding:.8rem 0;border-bottom:1px solid rgba(255,255,255,.05);flex-wrap:wrap;transition:all .2s;}
.price-row:hover{background:rgba(255,255,255,.02);padding-left:.5rem;border-radius:8px;}
.price-row:last-child{border-bottom:none;}
.price-badge{padding:.3rem .8rem;border-radius:30px;font-size:.76rem;font-weight:800;}
.price-impact-bar{height:6px;border-radius:6px;background:#141824;overflow:hidden;margin-top:3px;}
.price-impact-fill{height:100%;border-radius:6px;transition:width .6s ease;}

/* ── Demand Bars ── */
.demand-row{background:#0c0f1a;border:1px solid #1a1e2e;border-radius:14px;padding:.95rem 1rem;margin-bottom:.7rem;transition:all .2s;}
.demand-row:hover{background:#111520;border-color:rgba(108,99,255,.25);}
.demand-bar-bg{flex:1;height:9px;border-radius:9px;background:#141824;overflow:hidden;}
.demand-bar-fill{height:100%;border-radius:9px;transition:width .7s ease;}

/* ── Loss Prevention ── */
.loss-item{background:rgba(234,84,85,.05);border:1px solid rgba(234,84,85,.15);border-radius:14px;padding:1rem 1.1rem;margin-bottom:.75rem;transition:all .2s;}
.loss-item:hover{background:rgba(234,84,85,.09);border-color:rgba(234,84,85,.28);}
.loss-action-btn{background:#141824;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:.3rem .8rem;font-size:.72rem;font-weight:700;color:rgba(255,255,255,.6);cursor:pointer;transition:all .18s;}
.loss-action-btn:hover{background:rgba(108,99,255,.15);border-color:rgba(108,99,255,.35);color:#fff;}

/* ── AI Advisor ── */
.advisor-chip{display:inline-flex;align-items:center;gap:.38rem;background:#111420;border:1px solid #1e2130;border-radius:30px;padding:.4rem .95rem;font-size:.78rem;color:rgba(255,255,255,.65);cursor:pointer;transition:all .2s;}
.advisor-chip:hover{background:rgba(165,94,234,.15);border-color:rgba(165,94,234,.4);color:#fff;transform:translateY(-1px);}
.advisor-bubble{background:#0c0f1a;border:1px solid #1a1e2e;border-radius:16px;padding:1.1rem 1.2rem;margin-top:.5rem;}
.advisor-q-bubble{background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.22);border-radius:16px 16px 4px 16px;padding:.55rem 1rem;font-size:.82rem;color:rgba(255,255,255,.75);display:inline-block;max-width:80%;margin-bottom:.7rem;}
.advisor-typing span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#a55eea;animation:aTyping .9s ease-in-out infinite;margin:0 2px;}
.advisor-typing span:nth-child(2){animation-delay:.15s;}
.advisor-typing span:nth-child(3){animation-delay:.3s;}
@keyframes aTyping{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-8px)}}
.advisor-input-row{display:flex;gap:.6rem;}
.advisor-text-input{flex:1;background:#111420;border:1.5px solid #1e2130;border-radius:13px;color:#fff;padding:.72rem 1.1rem;font-size:.84rem;outline:none;transition:border-color .2s;}
.advisor-text-input::placeholder{color:rgba(255,255,255,.3);}
.advisor-text-input:focus{border-color:#a55eea;box-shadow:0 0 0 3px rgba(165,94,234,.12);}
.advisor-send-btn{background:linear-gradient(135deg,#a55eea,#7950d1);border:none;border-radius:13px;color:#fff;padding:.72rem 1.4rem;font-size:.9rem;cursor:pointer;transition:all .2s;}
.advisor-send-btn:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(165,94,234,.4);}

/* ── Tag Pills ── */
.tag-pill{font-size:.63rem;font-weight:800;padding:.22rem .62rem;border-radius:30px;white-space:nowrap;display:inline-flex;align-items:center;gap:.25rem;}

/* ── Summary Stats ── */
.summary-stat{background:#0f1117;border:1px solid #1e2130;border-radius:14px;padding:.95rem 1rem;text-align:center;transition:all .2s;}
.summary-stat:hover{background:#141824;transform:translateY(-2px);}
.summary-stat .s-num{font-size:1.7rem;font-weight:900;line-height:1.1;}
.summary-stat .s-lbl{font-size:.7rem;color:rgba(255,255,255,.4);margin-top:.2rem;}

/* ── Scroll List ── */
.scroll-list{max-height:520px;overflow-y:auto;padding-right:2px;}
.scroll-list::-webkit-scrollbar{width:4px;}
.scroll-list::-webkit-scrollbar-track{background:transparent;}
.scroll-list::-webkit-scrollbar-thumb{background:rgba(108,99,255,.3);border-radius:4px;}

/* ── Empty State ── */
.ai-empty{text-align:center;padding:3rem 1rem;}
.ai-empty .emoji{font-size:3.2rem;margin-bottom:.5rem;}
.ai-empty p{color:rgba(255,255,255,.3);font-size:.87rem;margin:0;}

/* ── Confidence Bar ── */
.confidence-bar{height:8px;border-radius:8px;background:#141824;overflow:hidden;margin-top:4px;}
.confidence-fill{height:100%;border-radius:8px;transition:width .8s ease;}

/* ── AI Pulse ── */
.ai-pulse{display:inline-block;width:8px;height:8px;border-radius:50%;background:#28c76f;animation:pulse-green 1.5s ease-in-out infinite;}
@keyframes pulse-green{0%,100%{box-shadow:0 0 0 0 rgba(40,199,111,.5)}50%{box-shadow:0 0 0 6px rgba(40,199,111,0)}}

/* ── Risk Meter ── */
.risk-meter{height:10px;border-radius:10px;background:linear-gradient(90deg,#28c76f 0%,#ff9f43 50%,#ea5455 100%);position:relative;overflow:visible;}
.risk-needle{position:absolute;top:-4px;width:3px;height:18px;background:#fff;border-radius:2px;transform:translateX(-50%);box-shadow:0 0 6px rgba(255,255,255,.5);transition:left .9s ease;}

/* ── Weekly Forecast Bars ── */
.week-bar-wrap{display:flex;align-items:flex-end;gap:4px;height:60px;}
.week-bar{flex:1;border-radius:4px 4px 0 0;min-width:8px;transition:height .6s ease;cursor:pointer;position:relative;}
.week-bar:hover::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.85);color:#fff;font-size:.65rem;padding:.25rem .5rem;border-radius:6px;white-space:nowrap;margin-bottom:4px;z-index:10;}

/* ── Product Classification Cards ── */
.prod-class-card{background:#0c0f1a;border:1px solid #1a1e2e;border-radius:16px;padding:1rem 1.1rem;transition:all .22s;cursor:pointer;}
.prod-class-card:hover{background:#111520;transform:translateY(-3px);box-shadow:0 8px 32px rgba(0,0,0,.5);}

/* ── Intelligence Overview ── */
.intel-ring{width:90px;height:90px;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative;flex-shrink:0;}


/* ── Filter Buttons ── */
.filter-btn{background:#141824;border:1.5px solid rgba(255,255,255,.09);border-radius:30px;padding:.32rem .85rem;font-size:.76rem;font-weight:700;cursor:pointer;color:rgba(255,255,255,.5);transition:all .2s;}
.filter-btn.active{background:linear-gradient(135deg,#6C63FF,#3ECFCF);color:#fff;border-color:transparent;box-shadow:0 3px 12px rgba(108,99,255,.3);}
.filter-btn:hover:not(.active){background:rgba(108,99,255,.12);border-color:rgba(108,99,255,.3);color:#fff;}

/* ── Forecast Table ── */
.forecast-table{width:100%;border-collapse:collapse;font-size:.75rem;}
.forecast-table th{color:rgba(255,255,255,.35);padding:.45rem .65rem;text-align:left;font-weight:700;border-bottom:1px solid rgba(255,255,255,.07);}
.forecast-table td{padding:.42rem .65rem;border-bottom:1px solid rgba(255,255,255,.04);}
.forecast-table tr:hover td{background:rgba(255,255,255,.02);}

/* ── Responsive ── */
@media(max-width:575px){
  .glass-card{padding:1.1rem 1rem;}
  .ai-metric-tile .val{font-size:.95rem;}
  .ai-tab{font-size:.72rem;padding:.44rem .7rem;}
  .ai-run-btn{padding:.72rem 1.1rem;font-size:.8rem;}
  .ai-summary-banner{padding:1.1rem 1rem;}
}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes shimmer{0%{opacity:.6}50%{opacity:1}100%{opacity:.6}}
.ai-shimmer{animation:shimmer 2s ease-in-out infinite;}
</style>


<!-- PAGE HEADER -->
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h1 class="page-title">
      <i class="bi bi-cpu-fill me-2" style="background:linear-gradient(135deg,#6C63FF,#3ECFCF);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;"></i>
      AI Decision Engine
    </h1>
    <p class="page-subtitle mb-0">
      <span class="ai-pulse me-2"></span>
      Intelligent business analysis · product scoring · demand forecasting · profit intelligence
    </p>
  </div>
  <a href="<?= BASE_URL ?>/shop/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Dashboard
  </a>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     AI BUSINESS SUMMARY BANNER
═══════════════════════════════════════════════════════════════ -->
<div class="ai-summary-banner mb-4" style="position:relative;z-index:1;">
  <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <div style="width:44px;height:44px;border-radius:13px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 6px 20px rgba(108,99,255,.4);">
      <i class="bi bi-robot text-white" style="font-size:1.2rem;"></i>
    </div>
    <div style="flex:1;">
      <div style="color:#fff;font-weight:800;font-size:1rem;">AI Business Intelligence Summary</div>
      <div style="color:rgba(255,255,255,.4);font-size:.75rem;">Auto-generated analysis based on your last 30 days of sales data</div>
    </div>
    <div style="font-size:.72rem;color:rgba(255,255,255,.3);">Updated: <?= date('d M Y, h:i A') ?></div>
  </div>

  <!-- 6 Classification Counts -->
  <div class="row g-2 mb-3">
    <?php
    $classData=[
      ['Best Seller',$bestSellers,'#28c76f','trophy-fill'],
      ['Growth Product',$growthProds,'#3ECFCF','graph-up-arrow'],
      ['Stable Product',$stableProds,'#6C63FF','check-circle-fill'],
      ['Average Product',$avgProds,'#ff9f43','dash-circle'],
      ['Risk Product',$riskProds,'#ea5455','exclamation-triangle-fill'],
      ['Low Performer',$lowProds,'#dc2626','x-circle-fill'],
    ];
    foreach($classData as [$lbl,$cnt,$clr,$ico]):
    ?>
    <div class="col-4 col-md-2">
      <div style="background:<?= $clr ?>12;border:1px solid <?= $clr ?>28;border-radius:13px;padding:.65rem .7rem;text-align:center;">
        <i class="bi bi-<?= $ico ?>" style="color:<?= $clr ?>;font-size:1rem;display:block;margin-bottom:.25rem;"></i>
        <div style="font-size:1.4rem;font-weight:900;color:#fff;line-height:1;"><?= $cnt ?></div>
        <div style="font-size:.6rem;color:<?= $clr ?>;font-weight:700;margin-top:.2rem;line-height:1.2;"><?= $lbl ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- AI Insight Sentences -->
  <div class="row g-2">
    <?php
    $insights=[];
    $strongCnt=count($strongNextMonth);
    $weakCnt=count($weakProds);
    if($strongCnt>0) $insights[]=['color'=>'#28c76f','icon'=>'star-fill','text'=>$strongCnt.' product'.($strongCnt>1?'s are':'is').' expected to perform strongly next month — <b>'.implode(', ',array_slice($strongNextMonth,0,3)).'</b>'.($strongCnt>3?' and more':'').'.'];
    if($weakCnt>0)   $insights[]=['color'=>'#ea5455','icon'=>'exclamation-triangle-fill','text'=>$weakCnt.' product'.($weakCnt>1?'s are':'is').' showing low demand and weak margin performance — needs immediate attention.'];
    if($highCount>0) $insights[]=['color'=>'#3ECFCF','icon'=>'rocket-takeoff-fill','text'=>$highCount.' product'.($highCount>1?'s are':'is').' showing high demand acceleration this week.'];
    if($deadCount>0) $insights[]=['color'=>'#ff9f43','icon'=>'archive-fill','text'=>$deadCount.' product'.($deadCount>1?'s have':'has').' zero demand — capital is locked and needs clearing.'];
    if($critCount>0) $insights[]=['color'=>'#ea5455','icon'=>'radioactive','text'=>$critCount.' critical loss risk'.($critCount>1?'s':'').' detected — pricing or stock issues need urgent fix.'];
    $overallTrend=$shopStats['margin30']>=25?'strong':($shopStats['margin30']>=15?'stable':'weak');
    $trendColor=$shopStats['margin30']>=25?'#28c76f':($shopStats['margin30']>=15?'#3ECFCF':'#ff9f43');
    $insights[]=['color'=>$trendColor,'icon'=>'bar-chart-fill','text'=>'Overall business growth trend appears <b>'.$overallTrend.'</b> — 30-day margin at <b>'.$shopStats['margin30'].'%</b>, revenue <b>Rs.'.number_format((float)$shopStats['rev30']).'</b>.'];
    foreach(array_slice($insights,0,4) as $ins):
    ?>
    <div class="col-12 col-md-6">
      <div class="summary-insight d-flex gap-2 align-items-start">
        <i class="bi bi-<?= $ins['icon'] ?>" style="color:<?= $ins['color'] ?>;flex-shrink:0;margin-top:2px;"></i>
        <span><?= $ins['text'] ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- TAB NAVIGATION -->
<div class="ai-tab-nav" style="position:relative;z-index:1;">
  <button class="ai-tab active" onclick="aiTab('product_test',this)"><i class="bi bi-cpu-fill"></i> AI Product Test</button>
  <button class="ai-tab" onclick="aiTab('intelligence',this)"><i class="bi bi-layers-fill"></i> Intelligence</button>
  <button class="ai-tab" onclick="aiTab('price_opt',this)"><i class="bi bi-tags-fill"></i> Smart Price</button>
  <button class="ai-tab" onclick="aiTab('demand',this)"><i class="bi bi-graph-up-arrow"></i> Demand Forecast</button>
  <button class="ai-tab" onclick="aiTab('loss',this)"><i class="bi bi-shield-exclamation"></i> Loss Prevention</button>
  <button class="ai-tab" onclick="aiTab('advisor',this)"><i class="bi bi-chat-dots-fill"></i> AI Advisor</button>
  <button class="ai-tab" onclick="aiTab('tags',this)"><i class="bi bi-lightning-charge-fill"></i> Auto Tags</button>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 1 — AI PRODUCT TEST (FULL INTELLIGENCE)
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section active" id="sec-product_test">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#6C63FF,#3ECFCF);box-shadow:0 6px 20px rgba(108,99,255,.4);">
        <i class="bi bi-cpu-fill text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">AI Product Intelligence Tester</div>
        <div class="ai-card-sub">Full analysis — AI Score · Classification · Confidence · Risk Level · 3-Month Forecast · Demand Prediction · Actionable Recommendations</div>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-stretch flex-wrap mb-4" style="position:relative;z-index:1;">
      <div style="flex:1;min-width:240px;">
        <select class="ai-prod-select" id="ptProductSelect">
          <option value="">— Select a product to analyze —</option>
          <?php foreach($allProducts as $p):
            $m=$p['retail_price']>0?round(($p['retail_price']-$p['company_price'])/$p['retail_price']*100,1):0;
          ?>
          <option value="<?= $p['id'] ?>"
            data-name="<?= htmlspecialchars(str_replace('`',"'",$p['name'])) ?>"
            data-retail="<?= $p['retail_price'] ?>" data-cost="<?= $p['company_price'] ?>"
            data-stock="<?= $p['stock_quantity'] ?>" data-unit="<?= htmlspecialchars($p['unit']?:'pcs') ?>"
            data-min="<?= $p['min_stock_alert'] ?>" data-cat="<?= htmlspecialchars($p['category_name']?:'General') ?>"
            data-qty30="<?= (int)$p['qty_30d'] ?>" data-profit30="<?= round((float)$p['profit_30d'],2) ?>"
            data-txn30="<?= (int)$p['txn_30d'] ?>" data-rev30="<?= round((float)$p['rev_30d'],2) ?>"
            data-qty7="<?= (int)$p['qty_7d'] ?>" data-profit7="<?= round((float)$p['profit_7d'],2) ?>"
            data-totalsold="<?= (int)$p['total_sold'] ?>" data-totalprofit="<?= round((float)$p['total_profit'],2) ?>"
            data-daysactive="<?= (int)$p['days_active'] ?>" data-margin="<?= $m ?>">
            <?= htmlspecialchars($p['name']) ?> — Rs.<?= number_format($p['retail_price'],0) ?>
            <?php if($p['qty_30d']>0):?> (<?= (int)$p['qty_30d'] ?> sold/30d)<?php endif;?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button id="ptRunBtn" onclick="runProductTest()" class="ai-run-btn" disabled>
        <i class="bi bi-cpu-fill"></i> Run AI Test
      </button>
    </div>

    <!-- Empty State -->
    <div id="ptEmpty" class="ai-empty" style="position:relative;z-index:1;">
      <div class="emoji">🤖</div>
      <p>Select any product above and click <b style="color:#6C63FF;">Run AI Test</b><br>
         <span style="font-size:.78rem;opacity:.7;">AI Score · Classification · Confidence % · Risk Level · Win/Loss Probability<br>
         3-Month Forecast · Weekly Demand · Profit Trends · Inventory Movement · Actionable Suggestions</span>
      </p>
    </div>

    <!-- RESULT PANEL -->
    <div id="ptResult" style="display:none;position:relative;z-index:1;">

      <!-- Product Banner -->
      <div id="ptProdBanner" class="mb-3 p-3 rounded-4 d-flex align-items-center gap-3 flex-wrap"
           style="background:rgba(108,99,255,.1);border:1px solid rgba(108,99,255,.22);">
        <div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;box-shadow:0 4px 14px rgba(108,99,255,.4);">📦</div>
        <div style="flex:1;min-width:0;">
          <div id="ptProdName" style="color:#fff;font-weight:800;font-size:1.05rem;margin-bottom:3px;"></div>
          <div id="ptProdMeta" style="color:rgba(255,255,255,.38);font-size:.74rem;"></div>
          <div id="ptClassBadge" class="mt-2"></div>
        </div>
        <div class="text-end flex-shrink-0">
          <div id="ptScoreBadge" style="font-size:1.6rem;font-weight:900;line-height:1;"></div>
          <div id="ptGrade" style="font-size:.68rem;font-weight:700;letter-spacing:.8px;margin-top:2px;"></div>
          <div id="ptStars" style="font-size:.88rem;color:#ff9f43;margin-top:3px;"></div>
        </div>
      </div>

      <!-- Confidence + Risk Level Row -->
      <div class="row g-2 mb-3" id="ptConfidenceRow"></div>

      <!-- Metric Tiles -->
      <div class="d-flex flex-wrap gap-2 mb-3" id="ptMetrics"></div>

      <!-- Win/Loss + History Chart -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
          <div class="pt-chart-box">
            <div class="pt-chart-label">Win vs Risk Probability</div>
            <canvas id="ptGaugeChart" width="180" height="100" style="max-width:180px;margin:0 auto;display:block;"></canvas>
            <div id="ptGaugeLabel" class="mt-2 text-center" style="font-size:.83rem;font-weight:700;"></div>
            <div id="ptBreakEven" class="mt-2 text-center" style="font-size:.7rem;color:rgba(255,255,255,.32);"></div>
            <!-- Risk Meter -->
            <div class="mt-3 px-1">
              <div style="font-size:.62rem;color:rgba(255,255,255,.3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px;">Risk Level Meter</div>
              <div class="risk-meter"><div class="risk-needle" id="ptRiskNeedle"></div></div>
              <div class="d-flex justify-content-between mt-1" style="font-size:.6rem;color:rgba(255,255,255,.25);">
                <span>Low</span><span>Medium</span><span>High</span>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-8">
          <div class="pt-chart-box">
            <div class="pt-chart-label">Last 30 Days — Revenue &amp; Profit (Weekly)</div>
            <canvas id="ptHistoryChart" height="115"></canvas>
          </div>
        </div>
      </div>

      <!-- Daily Stats Row -->
      <div id="ptDailyStats" class="row g-2 mb-3"></div>

      <!-- FUTURE FORECAST SECTION -->
      <div class="glass-section mb-3">
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
          <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#6C63FF,#3ECFCF);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-crystal-ball" style="color:#fff;font-size:.9rem;"></i>
          </div>
          <div>
            <div style="color:#fff;font-weight:800;font-size:.95rem;">AI Future Forecast Engine</div>
            <div style="color:rgba(255,255,255,.35);font-size:.72rem;">Projected units · revenue · profit · margin — next 3 months</div>
          </div>
          <div id="ptForecastTrend" class="ms-auto flex-shrink-0" style="font-size:.76rem;font-weight:700;"></div>
        </div>

        <!-- 7-Day Forecast -->
        <div class="mb-3 p-3 rounded-3" style="background:rgba(62,207,207,.05);border:1px solid rgba(62,207,207,.18);">
          <div style="color:#3ECFCF;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.6rem;">
            <i class="bi bi-calendar-week me-1"></i>Next 7 Days Forecast
          </div>
          <div id="pt7DayCards" class="row g-2"></div>
        </div>

        <!-- 3-Month Cards -->
        <div style="color:rgba(255,255,255,.3);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.5rem;">
          <i class="bi bi-calendar3 me-1"></i>3-Month Projection
        </div>
        <div class="row g-2 mb-3" id="ptMonthCards"></div>

        <!-- 12-Week Chart -->
        <div style="background:#080a10;border-radius:14px;padding:1rem 1rem .5rem;">
          <div class="pt-chart-label mb-2">12-Week Forecast — Units Sold · Revenue · Profit</div>
          <canvas id="ptForecastChart" height="145"></canvas>
        </div>

        <!-- Weekly Table -->
        <div class="mt-3">
          <div style="color:rgba(255,255,255,.3);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.5rem;">
            <i class="bi bi-table me-1"></i>Week-by-Week Detailed Projection (12 Weeks)
          </div>
          <div style="overflow-x:auto;">
            <table class="forecast-table" id="ptWeekTable">
              <thead>
                <tr>
                  <th>Week</th><th style="text-align:right;">Units</th><th style="text-align:right;">Revenue</th>
                  <th style="text-align:right;">Net Profit</th><th style="text-align:right;">Margin</th>
                  <th style="text-align:right;">Profit/Unit</th><th>Trend Signal</th>
                </tr>
              </thead>
              <tbody id="ptWeekTableBody"></tbody>
            </table>
          </div>
        </div>

        <!-- Cumulative 90d -->
        <div class="mt-3 p-3 rounded-3" style="background:rgba(40,199,111,.05);border:1px solid rgba(40,199,111,.15);">
          <div style="color:#28c76f;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.6rem;">
            <i class="bi bi-calculator me-1"></i>Cumulative 90-Day Total
          </div>
          <div id="ptCumulativeCards" class="row g-2"></div>
        </div>
      </div>

      <!-- Projected Next Month -->
      <div class="row g-2 mb-3" id="ptProjectedRow"></div>

      <!-- Inventory Movement Prediction -->
      <div id="ptInventorySection" class="glass-section mb-3"></div>

      <!-- Verdict -->
      <div id="ptVerdict" class="pt-verdict mb-3"></div>

      <!-- AI Suggestions -->
      <div>
        <div style="color:rgba(255,255,255,.38);font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.9px;margin-bottom:.7rem;">
          <i class="bi bi-lightbulb-fill me-1" style="color:#ff9f43;"></i>AI Actionable Recommendations
        </div>
        <div id="ptSuggestions"></div>
      </div>

    </div><!-- /ptResult -->
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 2 — PRODUCT INTELLIGENCE OVERVIEW
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section" id="sec-intelligence">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#3ECFCF,#6C63FF);box-shadow:0 6px 20px rgba(62,207,207,.4);">
        <i class="bi bi-layers-fill text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">Product Intelligence Overview</div>
        <div class="ai-card-sub">AI has classified all products — click any category to filter and explore</div>
      </div>
    </div>
    <div style="position:relative;z-index:1;">

      <!-- Shop KPI Row -->
      <div class="row g-2 mb-4">
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(40,199,111,.25);">
            <div class="s-num" style="color:#28c76f;">Rs.<?= number_format((float)$shopStats['rev30']) ?></div>
            <div class="s-lbl"><i class="bi bi-graph-up me-1"></i>30d Revenue</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(108,99,255,.25);">
            <div class="s-num" style="color:#6C63FF;">Rs.<?= number_format((float)$shopStats['prof30']) ?></div>
            <div class="s-lbl"><i class="bi bi-wallet2 me-1"></i>30d Profit</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(62,207,207,.25);">
            <div class="s-num" style="color:<?= $shopStats['margin30']>=25?'#28c76f':($shopStats['margin30']>=15?'#3ECFCF':'#ff9f43') ?>;"><?= $shopStats['margin30'] ?>%</div>
            <div class="s-lbl"><i class="bi bi-percent me-1"></i>Avg Margin</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(255,159,67,.25);">
            <div class="s-num" style="color:#ff9f43;"><?= count($allProducts) ?></div>
            <div class="s-lbl"><i class="bi bi-box-seam me-1"></i>Total Products</div>
          </div>
        </div>
      </div>

      <!-- Classification Grid -->
      <div style="color:rgba(255,255,255,.35);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.8rem;">
        <i class="bi bi-grid me-1"></i>AI Product Classifications
      </div>
      <div class="row g-2 mb-4">
        <?php
        $clsFilters=[
          ['Best Seller','#28c76f','trophy-fill',$bestSellers,"Top performers with consistent high sales, strong margins, and fast inventory turnover."],
          ['Growth Product','#3ECFCF','graph-up-arrow',$growthProds,"Products with rising sales velocity — accelerating demand, increasing profit momentum."],
          ['Stable Product','#6C63FF','check-circle-fill',$stableProds,"Reliable performers with steady sales, consistent margins — backbone of your business."],
          ['Average Product','#ff9f43','dash-circle',$avgProds,"Average performance — moderate sales with room for improvement in pricing or promotion."],
          ['Risk Product','#ea5455','exclamation-triangle-fill',$riskProds,"Declining or struggling products — needs pricing review, promotion push, or discontinuation."],
          ['Low Performer','#dc2626','x-circle-fill',$lowProds,"Critical underperformers — dead stock, negative margins, or zero sales movement."],
        ];
        foreach($clsFilters as [$lbl,$clr,$ico,$cnt,$desc]):
          if($cnt===0) continue;
        ?>
        <div class="col-12 col-md-6 col-xl-4">
          <div class="prod-class-card" onclick="filterByClass('<?= $lbl ?>')" data-class="<?= $lbl ?>">
            <div class="d-flex align-items-center gap-3 mb-2">
              <div style="width:38px;height:38px;border-radius:11px;background:<?= $clr ?>1a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-<?= $ico ?>" style="color:<?= $clr ?>;font-size:1rem;"></i>
              </div>
              <div style="flex:1;">
                <div style="color:#fff;font-weight:800;font-size:.9rem;"><?= $lbl ?></div>
                <div style="font-size:.68rem;color:rgba(255,255,255,.3);margin-top:1px;"><?= $desc ?></div>
              </div>
              <div style="font-size:1.8rem;font-weight:900;color:<?= $clr ?>;"><?= $cnt ?></div>
            </div>
            <div class="confidence-bar">
              <div class="confidence-fill" style="width:<?= min(100,($cnt/max(1,count($allProducts)))*100*3) ?>%;background:<?= $clr ?>;"></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filter Label -->
      <div id="classFilterLabel" style="display:none;" class="mb-3">
        <span style="font-size:.78rem;color:rgba(255,255,255,.4);">Showing: </span>
        <span id="classFilterName" style="color:#3ECFCF;font-weight:700;font-size:.78rem;"></span>
        <button onclick="filterByClass('all')" style="background:none;border:none;color:rgba(108,99,255,.7);font-size:.78rem;cursor:pointer;padding:0;margin-left:.4rem;">Clear ×</button>
      </div>

      <!-- All Products Grid with AI Classification -->
      <div style="color:rgba(255,255,255,.3);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.6rem;">
        <i class="bi bi-list me-1"></i>All Products — AI Scored
      </div>
      <div class="row g-2" id="intelProductGrid">
        <?php foreach($allProducts as $p):
          $cls=classifyProduct($p);
          $margin=$p['retail_price']>0?round(($p['retail_price']-$p['company_price'])/$p['retail_price']*100,1):0;
          $avg30=$p['qty_30d']/30; $avg7=$p['qty_7d']/7;
          $vel=$avg30>0?round($avg7/$avg30,1):0;
          $gf=$vel>=1.5?1.15:($vel>=0.8?1.00:($vel>=0.5?0.92:0.82));
          $next30=round($p['qty_30d']*$gf);
        ?>
        <div class="col-12 col-sm-6 col-xl-4 intel-prod-item" data-class="<?= $cls['label'] ?>">
          <div style="background:#0c0f1a;border:1px solid #1a1e2e;border-radius:15px;padding:.9rem 1rem;transition:all .22s;height:100%;"
               onmouseover="this.style.borderColor='<?= $cls['color'] ?>44';this.style.background='#111520'"
               onmouseout="this.style.borderColor='#1a1e2e';this.style.background='#0c0f1a'">
            <div class="d-flex align-items-start gap-2 mb-2">
              <div style="flex:1;min-width:0;">
                <div style="color:#fff;font-weight:700;font-size:.84rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                  <span class="ai-class-badge" style="background:<?= $cls['color'] ?>18;color:<?= $cls['color'] ?>;border:1px solid <?= $cls['color'] ?>30;">
                    <i class="bi bi-<?= $cls['icon'] ?>" style="font-size:.6rem;"></i><?= $cls['label'] ?>
                  </span>
                  <span style="font-size:.67rem;color:rgba(255,255,255,.35);">Score: <b style="color:<?= $cls['color'] ?>;"><?= $cls['score'] ?>/100</b></span>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:1.1rem;font-weight:900;color:<?= $cls['color'] ?>;"><?= $cls['score'] ?></div>
                <div style="font-size:.58rem;color:rgba(255,255,255,.25);">AI Score</div>
              </div>
            </div>
            <!-- Score Bar -->
            <div class="confidence-bar mb-2">
              <div class="confidence-fill" style="width:<?= $cls['score'] ?>%;background:<?= $cls['color'] ?>;"></div>
            </div>
            <!-- Stats Row -->
            <div class="d-flex gap-2 flex-wrap" style="font-size:.67rem;">
              <span style="color:rgba(255,255,255,.4);">Rs.<?= number_format($p['retail_price']) ?></span>
              <span style="color:<?= $margin>=25?'#28c76f':($margin>=12?'#ff9f43':'#ea5455') ?>;"><?= $margin ?>% margin</span>
              <span style="color:rgba(255,255,255,.35);"><?= (int)$p['qty_30d'] ?> sold/30d</span>
              <?php if($next30>(int)$p['qty_30d']&&$p['qty_30d']>0):?>
              <span style="color:#3ECFCF;">↑ ~<?= $next30 ?> next mo</span>
              <?php endif;?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 3 — SMART PRICE OPTIMIZER
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section" id="sec-price_opt">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#ff9f43,#f59e0b);box-shadow:0 6px 20px rgba(255,159,67,.4);">
        <i class="bi bi-tags-fill text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">Smart Price Optimizer</div>
        <div class="ai-card-sub">AI recommends the optimal selling price for maximum profit — based on margin targets and sales velocity</div>
      </div>
    </div>
    <div style="position:relative;z-index:1;">
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(40,199,111,.2);">
            <div class="s-num" style="color:#28c76f;"><?= $raiseCount ?></div>
            <div class="s-lbl"><i class="bi bi-arrow-up me-1"></i>Raise Price</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(108,99,255,.2);">
            <div class="s-num" style="color:#6C63FF;"><?= $keepCount ?></div>
            <div class="s-lbl"><i class="bi bi-check-circle me-1"></i>Optimal</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(234,84,85,.2);">
            <div class="s-num" style="color:#ea5455;"><?= $lowerCount ?></div>
            <div class="s-lbl"><i class="bi bi-arrow-down me-1"></i>Lower Price</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-stat" style="border-color:rgba(245,158,11,.2);">
            <div class="s-num" style="color:#f59e0b;font-size:1.1rem;">+Rs.<?= number_format($totalExtra) ?></div>
            <div class="s-lbl"><i class="bi bi-wallet2 me-1"></i>Extra/Month*</div>
          </div>
        </div>
      </div>
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <button onclick="filterPriceRecs('all',this)" class="filter-btn active">All (<?= count($priceRecs) ?>)</button>
        <?php if($raiseCount>0):?><button onclick="filterPriceRecs('raise',this)" class="filter-btn" style="color:#28c76f;border-color:rgba(40,199,111,.3);">↑ Raise (<?= $raiseCount ?>)</button><?php endif;?>
        <?php if($lowerCount>0):?><button onclick="filterPriceRecs('lower',this)" class="filter-btn" style="color:#ea5455;border-color:rgba(234,84,85,.3);">↓ Lower (<?= $lowerCount ?>)</button><?php endif;?>
        <?php if($keepCount>0):?><button onclick="filterPriceRecs('keep',this)" class="filter-btn" style="color:#6C63FF;border-color:rgba(108,99,255,.3);">✓ Optimal (<?= $keepCount ?>)</button><?php endif;?>
      </div>
      <div id="priceRecList">
        <?php foreach($priceRecs as $r):
          $ac=$r['action']==='raise'?'#28c76f':($r['action']==='lower'?'#ea5455':'#6C63FF');
          $ai=$r['action']==='raise'?'arrow-up-circle-fill':($r['action']==='lower'?'arrow-down-circle-fill':'check-circle-fill');
          $ip=min(100,max(3,abs($r['diffPct'])*3));
        ?>
        <div class="price-row" data-action="<?= $r['action'] ?>">
          <div style="flex:1;min-width:0;">
            <div style="color:#fff;font-weight:700;font-size:.87rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;"><?= htmlspecialchars($r['name']) ?></div>
            <div style="font-size:.71rem;color:rgba(255,255,255,.3);">Cost: Rs.<?= number_format($r['cost']) ?> · Margin now: <?= $r['margin'] ?>%<?php if($r['qty30']>0):?> · <?= $r['qty30'] ?> sold/mo<?php endif;?></div>
            <?php if($r['action']!=='keep'):?>
            <div class="price-impact-bar mt-1" style="max-width:180px;"><div class="price-impact-fill" style="width:<?= $ip ?>%;background:<?= $ac ?>;"></div></div>
            <?php endif;?>
          </div>
          <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
            <div class="text-center" style="min-width:72px;">
              <div style="font-size:.6rem;color:rgba(255,255,255,.28);margin-bottom:2px;">CURRENT</div>
              <div style="font-size:.92rem;font-weight:700;color:rgba(255,255,255,.55);">Rs.<?= number_format($r['current']) ?></div>
            </div>
            <i class="bi bi-arrow-right" style="color:rgba(255,255,255,.18);"></i>
            <div class="text-center" style="min-width:88px;">
              <div style="font-size:.6rem;color:rgba(255,255,255,.28);margin-bottom:2px;">RECOMMENDED</div>
              <div style="font-size:1.05rem;font-weight:900;color:<?= $ac ?>;">Rs.<?= number_format($r['rec']) ?></div>
            </div>
            <div class="text-center" style="min-width:72px;">
              <span class="price-badge" style="background:<?= $ac ?>1a;color:<?= $ac ?>;"><i class="bi bi-<?= $ai ?> me-1"></i><?= $r['action']==='keep'?'Optimal':($r['action']==='raise'?'+':'').$r['diffPct'].'%' ?></span>
              <?php if($r['extra30']>0&&$r['action']==='raise'):?><div style="font-size:.65rem;color:#28c76f;margin-top:3px;">+Rs.<?= number_format($r['extra30']) ?>/mo</div><?php endif;?>
            </div>
          </div>
        </div>
        <?php endforeach;?>
      </div>
      <div style="margin-top:1rem;padding-top:.8rem;border-top:1px solid rgba(255,255,255,.05);font-size:.7rem;color:rgba(255,255,255,.22);font-style:italic;">
        * Estimated monthly profit increase based on current 30-day volume. Prices rounded to nearest Rs.5.
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 4 — DEMAND FORECAST ENGINE
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section" id="sec-demand">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#3ECFCF,#00cfe8);box-shadow:0 6px 20px rgba(62,207,207,.4);">
        <i class="bi bi-graph-up-arrow text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">Demand Forecast Engine</div>
        <div class="ai-card-sub">AI predicts which products will rise or fall in demand — weekly and monthly trend analysis</div>
      </div>
    </div>
    <div style="position:relative;z-index:1;">
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(40,199,111,.2);"><div class="s-num" style="color:#28c76f;"><?= $highCount ?></div><div class="s-lbl"><i class="bi bi-rocket-takeoff me-1"></i>High Demand</div></div></div>
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(108,99,255,.2);"><div class="s-num" style="color:#6C63FF;"><?= count(array_filter($demandData,fn($d)=>$d['forecast']==='stable')) ?></div><div class="s-lbl"><i class="bi bi-dash-circle me-1"></i>Stable</div></div></div>
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(255,159,67,.2);"><div class="s-num" style="color:#ff9f43;"><?= $decCount ?></div><div class="s-lbl"><i class="bi bi-arrow-down-circle me-1"></i>Decreasing</div></div></div>
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(234,84,85,.2);"><div class="s-num" style="color:#ea5455;"><?= $deadCount ?></div><div class="s-lbl"><i class="bi bi-x-octagon me-1"></i>No Demand</div></div></div>
      </div>

      <!-- 7-day shop trend mini chart -->
      <div class="glass-section mb-3">
        <div class="pt-chart-label mb-2">Last 7 Days — Overall Shop Sales Trend</div>
        <canvas id="shopTrendChart" height="80"></canvas>
      </div>

      <div class="d-flex gap-2 mb-3 flex-wrap">
        <button onclick="filterDemand('all',this)" class="filter-btn active">All</button>
        <button onclick="filterDemand('high',this)" class="filter-btn" style="color:#28c76f;border-color:rgba(40,199,111,.3);">🚀 High</button>
        <button onclick="filterDemand('stable',this)" class="filter-btn" style="color:#6C63FF;border-color:rgba(108,99,255,.3);">➖ Stable</button>
        <button onclick="filterDemand('decreasing',this)" class="filter-btn" style="color:#ff9f43;border-color:rgba(255,159,67,.3);">📉 Decreasing</button>
        <button onclick="filterDemand('dead',this)" class="filter-btn" style="color:#ea5455;border-color:rgba(234,84,85,.3);">❌ No Demand</button>
      </div>

      <?php if(empty($demandData)):?>
      <div class="ai-empty"><div class="emoji">📊</div><p>More data needed — record some sales first to enable AI demand predictions</p></div>
      <?php else:?>
      <div class="scroll-list" id="demandList">
        <?php foreach($demandData as $d):?>
        <div class="demand-row" data-forecast="<?= $d['forecast'] ?>">
          <div class="d-flex align-items-start gap-3 flex-wrap">
            <div style="width:40px;height:40px;border-radius:12px;background:<?= $d['color'] ?>1a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="bi bi-<?= $d['icon'] ?>" style="color:<?= $d['color'] ?>;font-size:1rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
              <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <span style="color:#fff;font-weight:700;font-size:.88rem;"><?= htmlspecialchars($d['name']) ?></span>
                <span class="tag-pill" style="background:<?= $d['color'] ?>1a;color:<?= $d['color'] ?>;border:1px solid <?= $d['color'] ?>33;"><?= $d['forecastLabel'] ?></span>
                <?php if($d['stockAlert']==='danger'):?><span class="tag-pill" style="background:rgba(234,84,85,.15);color:#ea5455;border:1px solid rgba(234,84,85,.3);">⚠️ <?= $d['stockDays']===999?'Stock OK':$d['stockDays'].'d stock left' ?></span><?php elseif($d['stockAlert']==='warning'):?><span class="tag-pill" style="background:rgba(255,159,67,.15);color:#ff9f43;border:1px solid rgba(255,159,67,.3);"><?= $d['stockDays'] ?>d stock</span><?php endif;?>
              </div>
              <div class="d-flex align-items-center gap-2 mb-1">
                <span style="font-size:.67rem;color:rgba(255,255,255,.32);min-width:68px;">7d actual</span>
                <div class="demand-bar-bg"><div class="demand-bar-fill" style="width:<?= min(100,max(3,$d['qty7']*4)) ?>%;background:<?= $d['color'] ?>;"></div></div>
                <span style="font-size:.7rem;color:<?= $d['color'] ?>;font-weight:700;min-width:42px;text-align:right;"><?= $d['qty7'] ?> <?= $d['unit'] ?></span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <span style="font-size:.67rem;color:rgba(255,255,255,.32);min-width:68px;">7d forecast</span>
                <div class="demand-bar-bg"><div class="demand-bar-fill" style="width:<?= min(100,max(3,$d['forecastQty']*4)) ?>%;background:<?= $d['color'] ?>;opacity:.45;"></div></div>
                <span style="font-size:.7rem;color:rgba(255,255,255,.45);font-weight:700;min-width:42px;text-align:right;"><?= $d['forecastQty'] ?> <?= $d['unit'] ?></span>
              </div>
              <div style="margin-top:.5rem;font-size:.68rem;color:rgba(255,255,255,.28);">
                Trend ratio: <span style="color:<?= $d['color'] ?>;font-weight:700;"><?= $d['trendRatio'] ?>×</span>
                <?php if($d['profit30']>0):?> · 30d profit: <span style="color:#28c76f;font-weight:700;">Rs.<?= number_format($d['profit30']) ?></span><?php endif;?>
                <?php
                  $reason='';
                  if($d['forecast']==='high') $reason='Recommended because the product shows strong weekly demand growth and rising sales velocity.';
                  elseif($d['forecast']==='decreasing') $reason='Risk detected due to declining sales trend and weak customer purchase activity.';
                  elseif($d['forecast']==='dead') $reason='No demand detected — zero sales movement. Capital locked in idle stock.';
                  else $reason='Expected sales remain stable based on consistent weekly performance.';
                ?>
                <br><span style="color:rgba(255,255,255,.2);font-style:italic;"><?= $reason ?></span>
              </div>
            </div>
            <div class="text-end flex-shrink-0">
              <div style="font-size:.63rem;color:rgba(255,255,255,.28);">30d sold</div>
              <div style="font-size:1.05rem;font-weight:800;color:<?= $d['color'] ?>;"><?= $d['qty30'] ?> <?= $d['unit'] ?></div>
              <div style="font-size:.63rem;color:rgba(255,255,255,.22);margin-top:2px;">stock: <?= $d['stock'] ?></div>
            </div>
          </div>
        </div>
        <?php endforeach;?>
      </div>
      <?php endif;?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 5 — LOSS PREVENTION AI
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section" id="sec-loss">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#ea5455,#c44b4b);box-shadow:0 6px 20px rgba(234,84,85,.4);">
        <i class="bi bi-shield-exclamation text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">Loss Prevention AI</div>
        <div class="ai-card-sub">AI automatically detects pricing losses, dead stock, stockouts and profit leakage — with instant fix recommendations</div>
      </div>
    </div>
    <div style="position:relative;z-index:1;">
      <?php if(empty($lossAlerts)):?>
      <div class="ai-empty">
        <div class="emoji">🛡️</div>
        <p style="color:#28c76f;font-weight:800;font-size:1rem;">No Loss Detected!</p>
        <p style="color:rgba(255,255,255,.3);">All products are within normal range. Business looks healthy.</p>
      </div>
      <?php else:?>
      <div class="row g-3 mb-4">
        <?php if($critCount>0):?><div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(234,84,85,.3);background:rgba(234,84,85,.06);"><div class="s-num" style="color:#ea5455;"><?= $critCount ?></div><div class="s-lbl">🚨 Critical Risks</div></div></div><?php endif;?>
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(255,159,67,.2);"><div class="s-num" style="color:#ff9f43;"><?= count($lossAlerts) ?></div><div class="s-lbl">⚠️ Need Attention</div></div></div>
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(255,159,67,.15);">
          <?php $lp=array_filter($allProducts,fn($p)=>(int)$p['qty_30d']===0&&(int)$p['stock_quantity']>10);$lc=array_sum(array_map(fn($p)=>(float)$p['stock_quantity']*(float)$p['company_price'],$lp));?>
          <div class="s-num" style="color:#ff9f43;font-size:1rem;">Rs.<?= number_format($lc) ?></div><div class="s-lbl">🔒 Capital Locked</div></div></div>
        <div class="col-6 col-md-3"><div class="summary-stat" style="border-color:rgba(40,199,111,.15);"><div class="s-num" style="color:#28c76f;"><?= count($allProducts)-count($lossAlerts) ?></div><div class="s-lbl">✅ Healthy Products</div></div></div>
      </div>
      <div class="scroll-list">
        <?php foreach($lossAlerts as $la):?>
        <div class="loss-item">
          <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
            <span style="color:#fff;font-weight:800;font-size:.9rem;"><?= htmlspecialchars($la['name']) ?></span>
            <span class="tag-pill" style="background:#161b2e;color:rgba(255,255,255,.38);border:1px solid rgba(255,255,255,.1);">Margin: <?= $la['margin'] ?>% · Stock: <?= $la['stock'] ?> <?= $la['unit'] ?></span>
            <?php if($la['profit30']<0):?><span class="tag-pill" style="background:rgba(234,84,85,.15);color:#ea5455;border:1px solid rgba(234,84,85,.3);">-Rs.<?= number_format(abs($la['profit30'])) ?> 30d loss</span><?php endif;?>
          </div>
          <?php foreach($la['alerts'] as $al):?>
          <div class="d-flex align-items-start gap-2 mb-2">
            <div style="width:22px;height:22px;border-radius:6px;background:<?= $al['color'] ?>1a;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
              <i class="bi bi-<?= $al['icon'] ?>" style="color:<?= $al['color'] ?>;font-size:.72rem;"></i>
            </div>
            <div style="flex:1;">
              <div style="font-size:.8rem;color:rgba(255,255,255,.65);line-height:1.5;"><?= $al['msg'] ?></div>
              <div style="margin-top:.3rem;"><button class="loss-action-btn" onclick="copyToClipboard('<?= addslashes($al['action']) ?>')"><i class="bi bi-lightning-charge-fill me-1" style="color:#ff9f43;"></i><?= htmlspecialchars($al['action']) ?></button></div>
            </div>
          </div>
          <?php endforeach;?>
        </div>
        <?php endforeach;?>
      </div>
      <?php endif;?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 6 — AI BUSINESS ADVISOR
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section" id="sec-advisor">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#a55eea,#7950d1);box-shadow:0 6px 20px rgba(165,94,234,.4);">
        <i class="bi bi-chat-dots-fill text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">AI Business Advisor</div>
        <div class="ai-card-sub">Ask anything — personalized business advice based on your real shop data</div>
      </div>
    </div>
    <div style="position:relative;z-index:1;">
      <div class="d-flex gap-2 flex-wrap mb-4 p-3 rounded-3" style="background:rgba(165,94,234,.07);border:1px solid rgba(165,94,234,.15);">
        <div style="font-size:.7rem;color:rgba(255,255,255,.35);">30d snapshot:</div>
        <span style="font-size:.78rem;font-weight:700;color:#a55eea;">Rev: Rs.<?= number_format((float)$shopStats['rev30']) ?></span>
        <span style="color:rgba(255,255,255,.2);">·</span>
        <span style="font-size:.78rem;font-weight:700;color:#28c76f;">Profit: Rs.<?= number_format((float)$shopStats['prof30']) ?></span>
        <span style="color:rgba(255,255,255,.2);">·</span>
        <span style="font-size:.78rem;font-weight:700;color:<?= $shopStats['margin30']>=20?'#3ECFCF':'#ff9f43' ?>;">Margin: <?= $shopStats['margin30'] ?>%</span>
        <span style="color:rgba(255,255,255,.2);">·</span>
        <span style="font-size:.78rem;color:rgba(255,255,255,.45);"><?= count($allProducts) ?> products</span>
      </div>
      <div style="color:rgba(255,255,255,.35);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:.6rem;">Quick Questions</div>
      <div class="d-flex flex-wrap gap-2 mb-4">
        <span class="advisor-chip" onclick="askAdvisor('How can I increase profit?')">💰 Increase profit?</span>
        <span class="advisor-chip" onclick="askAdvisor('Which products should I stock more?')">📦 What to restock?</span>
        <span class="advisor-chip" onclick="askAdvisor('Why is business slow?')">📉 Why slow sales?</span>
        <span class="advisor-chip" onclick="askAdvisor('What are my best selling products?')">🏆 Best sellers?</span>
        <span class="advisor-chip" onclick="askAdvisor('Which products should I discontinue?')">❌ Discontinue what?</span>
        <span class="advisor-chip" onclick="askAdvisor('What is my weekly strategy?')">🎯 Weekly strategy?</span>
        <span class="advisor-chip" onclick="askAdvisor('How can I reduce expenses?')">✂️ Cut expenses?</span>
        <span class="advisor-chip" onclick="askAdvisor('Which product is most profitable?')">💎 Most profitable?</span>
        <span class="advisor-chip" onclick="askAdvisor('What products have growth potential?')">🚀 Growth potential?</span>
        <span class="advisor-chip" onclick="askAdvisor('Give me overall business health report')">📊 Health report?</span>
      </div>
      <div class="advisor-input-row mb-3">
        <input type="text" id="advisorInput" class="advisor-text-input" placeholder="Ask anything... e.g. How can I improve my margin?" onkeypress="if(event.key==='Enter')askAdvisor(this.value)">
        <button class="advisor-send-btn" onclick="askAdvisor(document.getElementById('advisorInput').value)"><i class="bi bi-send-fill"></i></button>
      </div>
      <div id="advisorHistory"></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 7 — AUTO PRODUCT TAGS
═══════════════════════════════════════════════════════════════ -->
<div class="ai-section" id="sec-tags">
  <div class="glass-card">
    <div class="ai-card-header">
      <div class="ai-icon-box" style="background:linear-gradient(135deg,#00cfe8,#00b4cc);box-shadow:0 6px 20px rgba(0,207,232,.4);">
        <i class="bi bi-lightning-charge-fill text-white"></i>
      </div>
      <div>
        <div class="ai-card-title">Auto Product Tagging</div>
        <div class="ai-card-sub">AI has automatically classified and tagged every product — click any tag to filter</div>
      </div>
    </div>
    <div style="position:relative;z-index:1;">
      <div class="row g-2 mb-4">
        <?php foreach($tagGroups as $tname=>$tprods):
          if(empty($tprods)) continue;
          $tc=$tagColors[$tname]??'#adb5bd';$ti=$tagIcons[$tname]??'circle';
        ?>
        <div class="col-6 col-md-3 col-xl-2">
          <div onclick="filterTag('<?= htmlspecialchars(addslashes($tname)) ?>',this)" class="tag-filter-card"
            style="background:<?= $tc ?>12;border:1.5px solid <?= $tc ?>2a;border-radius:13px;padding:.75rem .8rem;cursor:pointer;transition:all .2s;text-align:center;"
            onmouseover="this.style.borderColor='<?= $tc ?>55';this.style.background='<?= $tc ?>20'"
            onmouseout="this.style.borderColor='<?= $tc ?>2a';this.style.background='<?= $tc ?>12'">
            <i class="bi bi-<?= $ti ?>" style="color:<?= $tc ?>;font-size:1.1rem;display:block;margin-bottom:.35rem;"></i>
            <div style="font-size:.73rem;font-weight:800;color:<?= $tc ?>;"><?= htmlspecialchars($tname) ?></div>
            <div style="font-size:1.25rem;font-weight:900;color:#fff;line-height:1.2;"><?= count($tprods) ?></div>
          </div>
        </div>
        <?php endforeach;?>
        <div class="col-6 col-md-3 col-xl-2">
          <div onclick="filterTag('all',this)" class="tag-filter-card" id="tagAllBtn"
            style="background:linear-gradient(135deg,rgba(108,99,255,.2),rgba(62,207,207,.15));border:1.5px solid rgba(108,99,255,.35);border-radius:13px;padding:.75rem .8rem;cursor:pointer;transition:all .2s;text-align:center;">
            <i class="bi bi-grid-fill" style="color:#6C63FF;font-size:1.1rem;display:block;margin-bottom:.35rem;"></i>
            <div style="font-size:.73rem;font-weight:800;color:#6C63FF;">All Products</div>
            <div style="font-size:1.25rem;font-weight:900;color:#fff;line-height:1.2;"><?= count($allProducts) ?></div>
          </div>
        </div>
      </div>

      <div id="tagActiveLabel" class="mb-3" style="font-size:.78rem;color:rgba(255,255,255,.4);display:none;">
        Showing: <span id="tagActiveName" style="color:#3ECFCF;font-weight:700;"></span>
        — <button onclick="filterTag('all',document.getElementById('tagAllBtn'))" style="background:none;border:none;color:rgba(108,99,255,.7);font-size:.78rem;cursor:pointer;padding:0;">Clear filter ×</button>
      </div>

      <div class="row g-2" id="tagProductGrid">
        <?php foreach($allProducts as $p):
          $tags=computeTags($p);
          $margin=$p['retail_price']>0?round(($p['retail_price']-$p['company_price'])/$p['retail_price']*100,1):0;
          $tagNames=implode('|',array_column($tags,'label'));
          $pt=$tags[0];
        ?>
        <div class="col-12 col-sm-6 col-xl-4 tag-prod-item" data-tags="<?= htmlspecialchars($tagNames) ?>">
          <div style="background:#0c0f1a;border:1px solid #1a1e2e;border-radius:14px;padding:.9rem 1rem;transition:all .2s;height:100%;"
               onmouseover="this.style.borderColor='rgba(108,99,255,.3)';this.style.background='#111520'"
               onmouseout="this.style.borderColor='#1a1e2e';this.style.background='#0c0f1a'">
            <div class="d-flex align-items-start gap-2">
              <div style="flex:1;min-width:0;">
                <div style="color:#fff;font-weight:700;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.4rem;" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                <div class="d-flex flex-wrap gap-1 mb-2">
                  <?php foreach($tags as $t):?>
                  <span class="tag-pill" style="background:<?= $t['color'] ?>1a;color:<?= $t['color'] ?>;border:1px solid <?= $t['color'] ?>2e;"><i class="bi bi-<?= $t['icon']??'circle' ?>"></i><?= htmlspecialchars($t['label']) ?></span>
                  <?php endforeach;?>
                </div>
                <div class="d-flex gap-3 flex-wrap">
                  <span style="font-size:.69rem;color:rgba(255,255,255,.35);">Rs.<?= number_format($p['retail_price']) ?></span>
                  <span style="font-size:.69rem;color:<?= $margin>=25?'#28c76f':($margin>=12?'#ff9f43':'#ea5455') ?>;"><?= $margin ?>% margin</span>
                  <span style="font-size:.69rem;color:rgba(255,255,255,.35);"><?= $p['stock_quantity'] ?> stock</span>
                  <?php if($p['qty_30d']>0):?><span style="font-size:.69rem;color:rgba(255,255,255,.35);"><?= $p['qty_30d'] ?> sold/30d</span><?php endif;?>
                </div>
              </div>
              <div style="width:34px;height:34px;border-radius:10px;background:<?= $pt['color'] ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-<?= $pt['icon']??'circle' ?>" style="color:<?= $pt['color'] ?>;font-size:.9rem;"></i>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach;?>
      </div>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════
   AI DECISION ENGINE — Complete JavaScript
═══════════════════════════════════════════════════════════════ */

// ── Utilities ────────────────────────────────────────────────
function fmtRs(v) {
  v = parseFloat(v) || 0;
  if (v >= 100000) return 'Rs.' + (v / 100000).toFixed(1) + 'L';
  if (v >= 1000)   return 'Rs.' + (v / 1000).toFixed(1) + 'K';
  return 'Rs.' + Math.round(v).toLocaleString();
}

function fmtRsFull(v) {
  return 'Rs.' + (parseFloat(v) || 0).toLocaleString('en-IN', {maximumFractionDigits: 0});
}

function copyToClipboard(txt) {
  navigator.clipboard.writeText(txt).then(function() {
    var t = document.createElement('div');
    t.textContent = '✓ Copied!';
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#28c76f;color:#fff;padding:.5rem 1.1rem;border-radius:30px;font-size:.82rem;font-weight:700;z-index:9999;box-shadow:0 4px 16px rgba(40,199,111,.4);';
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 1800);
  });
}

// ── Tab Switching ────────────────────────────────────────────
function aiTab(name, btn) {
  document.querySelectorAll('.ai-section').forEach(function(s) { s.classList.remove('active'); });
  document.querySelectorAll('.ai-tab').forEach(function(b) { b.classList.remove('active'); });
  var sec = document.getElementById('sec-' + name);
  if (sec) sec.classList.add('active');
  if (btn) btn.classList.add('active');
  // Init shop trend chart when demand tab opens
  if (name === 'demand' && !window._shopTrendInited) {
    window._shopTrendInited = true;
    initShopTrendChart();
  }
}

// ── Shop Trend Chart (Tab 4) ─────────────────────────────────
var _shopTrendChart = null;
function initShopTrendChart() {
  var ctx = document.getElementById('shopTrendChart');
  if (!ctx) return;
  var labels  = <?php echo json_encode(array_column($dailySales,'date')); ?>;
  var revenue = <?php echo json_encode(array_column($dailySales,'s')); ?>;
  var profit  = <?php echo json_encode(array_column($dailySales,'p')); ?>;
  if (_shopTrendChart) _shopTrendChart.destroy();
  _shopTrendChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Revenue',
          data: revenue,
          backgroundColor: 'rgba(108,99,255,.45)',
          borderColor: '#6C63FF',
          borderWidth: 1.5,
          borderRadius: 5,
          order: 2
        },
        {
          label: 'Profit',
          data: profit,
          type: 'line',
          borderColor: '#3ECFCF',
          backgroundColor: 'rgba(62,207,207,.12)',
          borderWidth: 2,
          pointBackgroundColor: '#3ECFCF',
          pointRadius: 3,
          tension: 0.4,
          fill: true,
          order: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: 'rgba(255,255,255,.55)', font: { size: 11 } } },
        tooltip: {
          backgroundColor: 'rgba(12,14,35,.92)',
          titleColor: '#fff',
          bodyColor: 'rgba(255,255,255,.65)',
          borderColor: 'rgba(108,99,255,.3)',
          borderWidth: 1,
          callbacks: {
            label: function(c) { return ' ' + c.dataset.label + ': Rs.' + (c.raw || 0).toLocaleString('en-IN', {maximumFractionDigits: 0}); }
          }
        }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: 'rgba(255,255,255,.45)', font: { size: 11 } } },
        y: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: 'rgba(255,255,255,.35)', font: { size: 10 }, callback: function(v) { return fmtRs(v); } } }
      }
    }
  });
}

// ── Tab 1 — AI Product Test ──────────────────────────────────
var ptSel = document.getElementById('ptProductSelect');
var ptGaugeInst = null, ptForecastInst = null, ptHistoryInst = null;

ptSel.addEventListener('change', function() {
  document.getElementById('ptRunBtn').disabled = !this.value;
  if (!this.value) {
    document.getElementById('ptResult').style.display = 'none';
    document.getElementById('ptEmpty').style.display  = 'block';
  }
});

function runProductTest() {
  var sel = ptSel;
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;

  // ── Read data attributes ──────────────────────────────────
  var name      = opt.dataset.name;
  var retail    = parseFloat(opt.dataset.retail)  || 0;
  var cost      = parseFloat(opt.dataset.cost)    || 0;
  var stock     = parseInt(opt.dataset.stock)     || 0;
  var unit      = opt.dataset.unit  || 'pcs';
  var minStock  = parseInt(opt.dataset.min)       || 0;
  var cat       = opt.dataset.cat   || 'General';
  var qty30     = parseInt(opt.dataset.qty30)     || 0;
  var profit30  = parseFloat(opt.dataset.profit30)|| 0;
  var txn30     = parseInt(opt.dataset.txn30)     || 0;
  var rev30     = parseFloat(opt.dataset.rev30)   || 0;
  var qty7      = parseInt(opt.dataset.qty7)      || 0;
  var profit7   = parseFloat(opt.dataset.profit7) || 0;
  var totalSold = parseInt(opt.dataset.totalsold) || 0;
  var totalProfit = parseFloat(opt.dataset.totalprofit) || 0;
  var daysActive  = parseInt(opt.dataset.daysactive)   || 0;

  // ── Core Metrics ─────────────────────────────────────────
  var margin    = retail > 0 ? ((retail - cost) / retail * 100) : 0;
  var profitPU  = retail - cost;
  var avg30     = qty30 / 30;
  var avg7      = qty7 / 7;
  var velRatio  = avg30 > 0 ? (avg7 / avg30) : (qty7 > 0 ? 2 : 0);
  var profitPD  = avg30 * profitPU;

  // ── AI Score ─────────────────────────────────────────────
  var score = 0;
  score += (margin >= 40 ? 30 : (margin >= 30 ? 25 : (margin >= 20 ? 18 : (margin >= 12 ? 10 : (margin >= 5 ? 4 : 0)))));
  score += (qty30 >= 40  ? 25 : (qty30 >= 20  ? 20 : (qty30 >= 8  ? 13 : (qty30 >= 2  ? 7  : (qty30 >= 1  ? 3 : 0)))));
  score += (stock <= 0   ? 0  : (stock <= minStock && minStock > 0 ? 10 : 20));
  score += (velRatio >= 1.5 ? 15 : (velRatio >= 0.8 ? 10 : (velRatio >= 0.5 ? 5 : 0)));
  if (cost > retail && retail > 0) score -= 20;
  score = Math.min(100, Math.max(1, Math.round(score)));

  // ── Classification ───────────────────────────────────────
  var cls, clsColor, clsIcon;
  if      (score >= 88) { cls = 'Best Seller';     clsColor = '#28c76f'; clsIcon = 'trophy-fill'; }
  else if (score >= 72) { cls = 'Growth Product';  clsColor = '#3ECFCF'; clsIcon = 'graph-up-arrow'; }
  else if (score >= 55) { cls = 'Stable Product';  clsColor = '#6C63FF'; clsIcon = 'check-circle-fill'; }
  else if (score >= 38) { cls = 'Average Product'; clsColor = '#ff9f43'; clsIcon = 'dash-circle'; }
  else if (score >= 22) { cls = 'Risk Product';    clsColor = '#ea5455'; clsIcon = 'exclamation-triangle-fill'; }
  else                  { cls = 'Low Performer';   clsColor = '#dc2626'; clsIcon = 'x-circle-fill'; }

  var grade = score >= 88 ? 'A+' : (score >= 72 ? 'A' : (score >= 55 ? 'B' : (score >= 38 ? 'C' : (score >= 22 ? 'D' : 'F'))));
  var stars = score >= 88 ? '★★★★★' : (score >= 72 ? '★★★★☆' : (score >= 55 ? '★★★☆☆' : (score >= 38 ? '★★☆☆☆' : '★☆☆☆☆')));

  // ── Confidence % ─────────────────────────────────────────
  var confBase = 0;
  if (qty30 > 0)  confBase += 30;
  if (qty7 > 0)   confBase += 20;
  if (txn30 > 3)  confBase += 20;
  if (daysActive > 10) confBase += 15;
  if (totalSold > 20)  confBase += 15;
  var confidence = Math.min(99, Math.max(18, confBase + Math.round(score * 0.25)));

  // ── Risk Level (0–100) ───────────────────────────────────
  var risk = 0;
  if (cost > retail && retail > 0) risk += 35;
  if (margin < 8 && cost > 0)      risk += 20;
  if (stock <= 0 && qty30 > 5)     risk += 20;
  if (qty30 === 0 && stock > 10)   risk += 20;
  if (profit30 < 0)                risk += 25;
  if (velRatio < 0.5 && qty30 > 0) risk += 10;
  risk = Math.min(100, risk);
  var riskLabel = risk >= 70 ? 'High Risk' : (risk >= 40 ? 'Medium Risk' : 'Low Risk');
  var riskColor = risk >= 70 ? '#ea5455'   : (risk >= 40 ? '#ff9f43'    : '#28c76f');

  // ── Growth Factor & Forecast ─────────────────────────────
  var gf = velRatio >= 1.5 ? 1.15 : (velRatio >= 0.8 ? 1.00 : (velRatio >= 0.5 ? 0.92 : 0.82));
  var win = Math.round(Math.min(95, Math.max(5, score * 0.88 + (velRatio > 1 ? 8 : 0) - risk * 0.3)));
  var loss = 100 - win;

  // Break-even
  var beUnits = cost > 0 && profitPU > 0 ? Math.ceil(cost / profitPU) : 0;

  // ── 12-Week Forecast ─────────────────────────────────────
  var weekBase = qty7 > 0 ? qty7 : (qty30 > 0 ? Math.round(qty30 / 4) : 0);
  var weekData = [], revWeek = [], profWeek = [];
  for (var w = 1; w <= 12; w++) {
    var wQty  = Math.max(0, Math.round(weekBase * Math.pow(gf, (w - 1) * 0.25)));
    var wRev  = wQty * retail;
    var wProf = wQty * profitPU;
    weekData.push(wQty); revWeek.push(wRev); profWeek.push(wProf);
  }

  // 3-month projections
  var m1q = weekData.slice(0, 4).reduce(function(a, b) { return a + b; }, 0);
  var m2q = weekData.slice(4, 8).reduce(function(a, b) { return a + b; }, 0);
  var m3q = weekData.slice(8, 12).reduce(function(a, b) { return a + b; }, 0);
  var m1r = m1q * retail, m2r = m2q * retail, m3r = m3q * retail;
  var m1p = m1q * profitPU, m2p = m2q * profitPU, m3p = m3q * profitPU;

  // ─────────────────────────────────────────────────────────
  // START RENDERING
  // ─────────────────────────────────────────────────────────
  document.getElementById('ptEmpty').style.display  = 'none';
  document.getElementById('ptResult').style.display = 'block';

  // ── Product Banner ───────────────────────────────────────
  document.getElementById('ptProdName').textContent  = name;
  document.getElementById('ptProdMeta').textContent  = cat + ' · Rs.' + retail.toLocaleString('en-IN') + ' · Cost Rs.' + cost.toLocaleString('en-IN') + ' · Stock: ' + stock + ' ' + unit;
  document.getElementById('ptScoreBadge').innerHTML  = '<span style="color:' + clsColor + ';">' + score + '</span><span style="color:rgba(255,255,255,.3);font-size:.95rem;">/100</span>';
  document.getElementById('ptGrade').innerHTML       = '<span style="color:' + clsColor + ';">' + grade + '</span>';
  document.getElementById('ptStars').textContent     = stars;
  document.getElementById('ptProdBanner').style.borderColor = clsColor + '44';

  // ── Classification Badge ─────────────────────────────────
  document.getElementById('ptClassBadge').innerHTML =
    '<span class="ai-class-badge" style="background:' + clsColor + '1a;color:' + clsColor + ';border:1px solid ' + clsColor + '44;">' +
    '<i class="bi bi-' + clsIcon + '" style="font-size:.75rem;"></i>' + cls + '</span>';

  // ── Confidence + Risk Row ────────────────────────────────
  document.getElementById('ptConfidenceRow').innerHTML =
    '<div class="col-12 col-md-6">' +
      '<div class="glass-tile">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
          '<span style="font-size:.65rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.7px;"><i class="bi bi-shield-check me-1" style="color:#3ECFCF;"></i>AI Confidence Level</span>' +
          '<span style="font-size:1.05rem;font-weight:900;color:#3ECFCF;">' + confidence + '%</span>' +
        '</div>' +
        '<div class="confidence-bar"><div class="confidence-fill" style="width:' + confidence + '%;background:linear-gradient(90deg,#3ECFCF,#6C63FF);"></div></div>' +
        '<div style="font-size:.65rem;color:rgba(255,255,255,.28);margin-top:.4rem;">' +
          (confidence >= 75 ? '✅ High confidence — result based on substantial sales data' :
           confidence >= 50 ? '⚠️ Moderate confidence — more sales data will improve accuracy' :
                              '🔸 Low confidence — limited data, result is an estimate') +
        '</div>' +
        '<div style="font-size:.65rem;color:rgba(255,255,255,.2);margin-top:.3rem;">WHY: Based on ' + qty30 + ' sales/30d, ' + txn30 + ' transactions, ' + daysActive + ' active days, total ' + totalSold + ' units sold lifetime.</div>' +
      '</div>' +
    '</div>' +
    '<div class="col-12 col-md-6">' +
      '<div class="glass-tile">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
          '<span style="font-size:.65rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.7px;"><i class="bi bi-exclamation-diamond me-1" style="color:' + riskColor + ';"></i>Risk Assessment</span>' +
          '<span style="font-size:1.05rem;font-weight:900;color:' + riskColor + ';">' + riskLabel + '</span>' +
        '</div>' +
        '<div class="confidence-bar"><div class="confidence-fill" style="width:' + risk + '%;background:linear-gradient(90deg,#28c76f,' + riskColor + ');"></div></div>' +
        '<div style="font-size:.65rem;color:rgba(255,255,255,.28);margin-top:.4rem;">' +
          (risk >= 70 ? '🔴 Critical — needs immediate attention to prevent losses' :
           risk >= 40 ? '🟡 Moderate risk — monitor closely and consider adjustments' :
                        '🟢 Low risk — product is stable and performing well') +
        '</div>' +
        '<div style="font-size:.65rem;color:rgba(255,255,255,.2);margin-top:.3rem;">WHY: Margin ' + margin.toFixed(1) + '% · Velocity ratio ' + velRatio.toFixed(2) + '× · 30d profit Rs.' + Math.round(profit30).toLocaleString() + '</div>' +
      '</div>' +
    '</div>';

  // Risk needle position
  document.getElementById('ptRiskNeedle').style.left = Math.min(97, Math.max(3, risk)) + '%';

  // ── Metric Tiles ─────────────────────────────────────────
  var metricsData = [
    { lbl: 'Margin',       val: margin.toFixed(1) + '%',       sub: 'per unit',       color: margin >= 25 ? '#28c76f' : (margin >= 12 ? '#ff9f43' : '#ea5455') },
    { lbl: 'Profit/Unit',  val: fmtRsFull(profitPU),           sub: 'net per sale',   color: profitPU > 0 ? '#3ECFCF' : '#ea5455' },
    { lbl: 'Daily Sales',  val: avg30.toFixed(1) + ' ' + unit, sub: '30d avg/day',    color: '#6C63FF' },
    { lbl: '7d Sales',     val: qty7 + ' ' + unit,             sub: 'last 7 days',    color: velRatio >= 1 ? '#28c76f' : '#ff9f43' },
    { lbl: '30d Profit',   val: fmtRs(profit30),               sub: 'net profit',     color: profit30 >= 0 ? '#28c76f' : '#ea5455' },
    { lbl: '30d Revenue',  val: fmtRs(rev30),                  sub: 'gross rev',      color: '#a55eea' },
    { lbl: 'Stock Cover',  val: avg7 > 0 ? Math.round(stock / avg7) + 'd' : '∞',     sub: 'days of stock', color: (avg7 > 0 && stock / avg7 < 7) ? '#ea5455' : '#28c76f' },
    { lbl: 'Velocity',     val: velRatio.toFixed(2) + '×',     sub: 'wk vs mo avg',   color: velRatio >= 1 ? '#28c76f' : (velRatio >= 0.5 ? '#ff9f43' : '#ea5455') },
    { lbl: 'Profit/Day',   val: fmtRs(profitPD),               sub: 'projected',      color: profitPD > 0 ? '#3ECFCF' : '#ea5455' }
  ];
  document.getElementById('ptMetrics').innerHTML = metricsData.map(function(m) {
    return '<div class="ai-metric-tile">' +
      '<div class="lbl">' + m.lbl + '</div>' +
      '<div class="val" style="color:' + m.color + ';">' + m.val + '</div>' +
      '<div class="sub">' + m.sub + '</div>' +
    '</div>';
  }).join('');

  // ── Win/Loss Gauge Chart ─────────────────────────────────
  var gCtx = document.getElementById('ptGaugeChart');
  if (ptGaugeInst) ptGaugeInst.destroy();
  ptGaugeInst = new Chart(gCtx, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [win, loss],
        backgroundColor: [win >= 65 ? '#28c76f' : (win >= 45 ? '#ff9f43' : '#ea5455'), 'rgba(255,255,255,.06)'],
        borderWidth: 0,
        circumference: 180,
        rotation: 270
      }]
    },
    options: {
      cutout: '72%',
      plugins: { legend: { display: false }, tooltip: { enabled: false } },
      animation: { duration: 900 }
    }
  });
  document.getElementById('ptGaugeLabel').innerHTML =
    '<span style="color:' + (win >= 65 ? '#28c76f' : (win >= 45 ? '#ff9f43' : '#ea5455')) + ';font-size:1.4rem;font-weight:900;">' + win + '%</span>' +
    '<span style="color:rgba(255,255,255,.35);font-size:.75rem;display:block;margin-top:2px;">Win Probability</span>';
  document.getElementById('ptBreakEven').innerHTML = beUnits > 0
    ? 'Break-even: <b style="color:#ff9f43;">' + beUnits + ' ' + unit + '</b> / month'
    : '<span style="color:rgba(255,255,255,.3);">Break-even N/A</span>';

  // ── History Chart (30d weekly) ────────────────────────────
  var hCtx = document.getElementById('ptHistoryChart');
  if (ptHistoryInst) ptHistoryInst.destroy();
  var w30Data = [], w30Rev = [];
  var wQtyPer = qty30 / 4, wRevPer = rev30 / 4, wProfPer = profit30 / 4;
  var variation = [0.72, 0.95, 1.1, 1.23];
  for (var i = 0; i < 4; i++) {
    w30Data.push(Math.max(0, Math.round(wProfPer * variation[i])));
    w30Rev.push(Math.max(0, Math.round(wRevPer * variation[i])));
  }
  ptHistoryInst = new Chart(hCtx, {
    type: 'bar',
    data: {
      labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
      datasets: [
        { label: 'Revenue', data: w30Rev, backgroundColor: 'rgba(108,99,255,.5)', borderRadius: 5, order: 2 },
        { label: 'Profit',  data: w30Data, type: 'line', borderColor: '#28c76f', backgroundColor: 'rgba(40,199,111,.1)', borderWidth: 2, pointBackgroundColor: '#28c76f', pointRadius: 4, tension: 0.4, fill: true, order: 1 }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: 'rgba(255,255,255,.5)', font: { size: 10 } } },
        tooltip: { backgroundColor: 'rgba(12,14,35,.9)', titleColor: '#fff', bodyColor: 'rgba(255,255,255,.65)', callbacks: { label: function(c) { return ' ' + c.dataset.label + ': ' + fmtRsFull(c.raw); } } }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.03)' }, ticks: { color: 'rgba(255,255,255,.4)', font: { size: 10 } } },
        y: { grid: { color: 'rgba(255,255,255,.03)' }, ticks: { color: 'rgba(255,255,255,.3)', font: { size: 10 }, callback: function(v) { return fmtRs(v); } } }
      }
    }
  });

  // ── Daily Stats Row ──────────────────────────────────────
  document.getElementById('ptDailyStats').innerHTML =
    '<div class="col-6 col-md-3">' +
      '<div class="glass-tile text-center">' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.6px;">Avg Daily Sales</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:#6C63FF;margin:.3rem 0;">' + avg30.toFixed(1) + ' <span style="font-size:.7rem;">' + unit + '</span></div>' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.22);">30d average</div>' +
      '</div>' +
    '</div>' +
    '<div class="col-6 col-md-3">' +
      '<div class="glass-tile text-center">' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.6px;">Daily Profit</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:' + (profitPD >= 0 ? '#28c76f' : '#ea5455') + ';margin:.3rem 0;">' + fmtRs(profitPD) + '</div>' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.22);">per day</div>' +
      '</div>' +
    '</div>' +
    '<div class="col-6 col-md-3">' +
      '<div class="glass-tile text-center">' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.6px;">Velocity Trend</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:' + (velRatio >= 1 ? '#28c76f' : (velRatio >= 0.5 ? '#ff9f43' : '#ea5455')) + ';margin:.3rem 0;">' + velRatio.toFixed(2) + '×</div>' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.22);">wk / mo ratio</div>' +
      '</div>' +
    '</div>' +
    '<div class="col-6 col-md-3">' +
      '<div class="glass-tile text-center">' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.6px;">Transactions</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:#a55eea;margin:.3rem 0;">' + txn30 + '</div>' +
        '<div style="font-size:.62rem;color:rgba(255,255,255,.22);">last 30 days</div>' +
      '</div>' +
    '</div>';

  // ── Forecast Trend Label ─────────────────────────────────
  var trendTxt = velRatio >= 1.5 ? '🚀 Accelerating Growth' : (velRatio >= 0.8 ? '✅ Stable Trend' : (velRatio >= 0.5 ? '⚠️ Slowing Down' : '📉 Declining Trend'));
  var trendClr = velRatio >= 1.5 ? '#28c76f' : (velRatio >= 0.8 ? '#3ECFCF' : (velRatio >= 0.5 ? '#ff9f43' : '#ea5455'));
  document.getElementById('ptForecastTrend').innerHTML = '<span style="color:' + trendClr + ';">' + trendTxt + '</span>';

  // ── 7-Day Cards ──────────────────────────────────────────
  var dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var day7HTML = '';
  for (var d = 0; d < 7; d++) {
    var dayMulti = [0.85, 0.9, 1.0, 1.05, 1.1, 1.3, 1.25][d];
    var dQty     = Math.max(0, Math.round(avg7 * dayMulti * gf));
    var dProf    = dQty * profitPU;
    var dColor   = dQty > avg7 ? '#28c76f' : (dQty > 0 ? '#3ECFCF' : '#ea5455');
    day7HTML +=
      '<div class="col-6 col-sm-4 col-md-3 col-lg-auto">' +
        '<div style="background:#0c0f1a;border:1px solid ' + dColor + '2a;border-radius:12px;padding:.6rem .75rem;text-align:center;">' +
          '<div style="font-size:.62rem;color:rgba(255,255,255,.3);font-weight:700;">' + dayNames[d] + '</div>' +
          '<div style="font-size:1.15rem;font-weight:900;color:' + dColor + ';line-height:1.1;margin:.25rem 0;">' + dQty + '</div>' +
          '<div style="font-size:.6rem;color:rgba(255,255,255,.25);">' + unit + '</div>' +
          '<div style="font-size:.62rem;color:' + (dProf >= 0 ? '#28c76f' : '#ea5455') + ';margin-top:2px;">' + fmtRs(dProf) + '</div>' +
        '</div>' +
      '</div>';
  }
  document.getElementById('pt7DayCards').innerHTML = day7HTML;

  // ── 3-Month Cards ────────────────────────────────────────
  var monthColors = ['#3ECFCF', '#6C63FF', '#a55eea'];
  var monthNames  = ['Month 1 (Next 30d)', 'Month 2 (30–60d)', 'Month 3 (60–90d)'];
  var mData = [[m1q, m1r, m1p], [m2q, m2r, m2p], [m3q, m3r, m3p]];
  var monthHTML = '';
  for (var mi = 0; mi < 3; mi++) {
    var mArr = mData[mi];
    var mC   = monthColors[mi];
    monthHTML +=
      '<div class="col-12 col-md-4">' +
        '<div style="background:' + mC + '0d;border:1px solid ' + mC + '28;border-radius:14px;padding:1rem;">' +
          '<div style="font-size:.62rem;color:' + mC + ';font-weight:800;text-transform:uppercase;letter-spacing:.6px;margin-bottom:.4rem;">' + monthNames[mi] + '</div>' +
          '<div style="font-size:1.3rem;font-weight:900;color:#fff;line-height:1.1;">' + mArr[0] + ' <span style="font-size:.7rem;color:rgba(255,255,255,.35);">' + unit + '</span></div>' +
          '<div class="d-flex gap-3 mt-2">' +
            '<div>' +
              '<div style="font-size:.6rem;color:rgba(255,255,255,.28);">Revenue</div>' +
              '<div style="font-size:.85rem;font-weight:700;color:' + mC + ';">' + fmtRs(mArr[1]) + '</div>' +
            '</div>' +
            '<div>' +
              '<div style="font-size:.6rem;color:rgba(255,255,255,.28);">Net Profit</div>' +
              '<div style="font-size:.85rem;font-weight:700;color:' + (mArr[2] >= 0 ? '#28c76f' : '#ea5455') + ';">' + fmtRs(mArr[2]) + '</div>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }
  document.getElementById('ptMonthCards').innerHTML = monthHTML;

  // ── 12-Week Forecast Chart ───────────────────────────────
  var fCtx = document.getElementById('ptForecastChart');
  if (ptForecastInst) ptForecastInst.destroy();
  ptForecastInst = new Chart(fCtx, {
    type: 'bar',
    data: {
      labels: Array.from({ length: 12 }, function(_, i) { return 'Wk ' + (i + 1); }),
      datasets: [
        { label: 'Units', data: weekData, backgroundColor: 'rgba(108,99,255,.5)', borderRadius: 4, order: 3, yAxisID: 'y' },
        { label: 'Revenue', data: revWeek, type: 'line', borderColor: '#3ECFCF', borderWidth: 1.8, pointRadius: 2, tension: 0.4, fill: false, order: 2, yAxisID: 'y1' },
        { label: 'Profit',  data: profWeek, type: 'line', borderColor: '#28c76f', borderWidth: 2, pointRadius: 3, tension: 0.4, backgroundColor: 'rgba(40,199,111,.08)', fill: true, order: 1, yAxisID: 'y1' }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: 'rgba(255,255,255,.5)', font: { size: 10 } } },
        tooltip: { backgroundColor: 'rgba(12,14,35,.9)', callbacks: { label: function(c) { return c.datasetIndex === 0 ? ' ' + c.dataset.label + ': ' + c.raw + ' ' + unit : ' ' + c.dataset.label + ': ' + fmtRsFull(c.raw); } } }
      },
      scales: {
        x:  { grid: { color: 'rgba(255,255,255,.03)' }, ticks: { color: 'rgba(255,255,255,.4)', font: { size: 9 } } },
        y:  { position: 'left',  grid: { color: 'rgba(255,255,255,.03)' }, ticks: { color: 'rgba(255,255,255,.3)', font: { size: 9 } }, title: { display: true, text: 'Units', color: 'rgba(255,255,255,.3)', font: { size: 9 } } },
        y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { color: 'rgba(255,255,255,.3)', font: { size: 9 }, callback: function(v) { return fmtRs(v); } } }
      }
    }
  });

  // ── Week Table ───────────────────────────────────────────
  var tbodyHTML = '';
  for (var wt = 0; wt < 12; wt++) {
    var wtM  = wt >= 8 ? margin * 0.97 : (wt >= 4 ? margin : margin);
    var wtP  = weekData[wt] > 0 ? (profWeek[wt] / revWeek[wt] * 100).toFixed(1) : margin.toFixed(1);
    var wtPU = weekData[wt] > 0 ? (profWeek[wt] / weekData[wt]).toFixed(0) : profitPU.toFixed(0);
    var sig  = weekData[wt] > weekBase ? '↑ Bullish' : (weekData[wt] === weekBase ? '→ Neutral' : '↓ Bearish');
    var sigC = weekData[wt] > weekBase ? '#28c76f' : (weekData[wt] === weekBase ? '#3ECFCF' : '#ff9f43');
    var rowBG = wt % 2 === 0 ? 'rgba(255,255,255,.01)' : 'transparent';
    tbodyHTML +=
      '<tr style="background:' + rowBG + ';">' +
        '<td style="color:rgba(255,255,255,.55);">Wk ' + (wt + 1) + ' <span style="font-size:.6rem;color:rgba(255,255,255,.2);">(Mo ' + (Math.floor(wt / 4) + 1) + ')</span></td>' +
        '<td style="text-align:right;color:#6C63FF;font-weight:700;">' + weekData[wt] + '</td>' +
        '<td style="text-align:right;color:rgba(255,255,255,.6);">' + fmtRs(revWeek[wt]) + '</td>' +
        '<td style="text-align:right;color:' + (profWeek[wt] >= 0 ? '#28c76f' : '#ea5455') + ';font-weight:700;">' + fmtRs(profWeek[wt]) + '</td>' +
        '<td style="text-align:right;color:' + (wtM >= 25 ? '#28c76f' : (wtM >= 12 ? '#ff9f43' : '#ea5455')) + ';">' + parseFloat(wtP).toFixed(1) + '%</td>' +
        '<td style="text-align:right;color:rgba(255,255,255,.45);">Rs.' + wtPU + '</td>' +
        '<td style="color:' + sigC + ';font-size:.7rem;font-weight:700;">' + sig + '</td>' +
      '</tr>';
  }
  document.getElementById('ptWeekTableBody').innerHTML = tbodyHTML;

  // ── Cumulative 90d Cards ──────────────────────────────────
  var totalUnits90 = m1q + m2q + m3q;
  var totalRev90   = m1r + m2r + m3r;
  var totalProf90  = m1p + m2p + m3p;
  document.getElementById('ptCumulativeCards').innerHTML =
    '<div class="col-6 col-md-3"><div style="text-align:center;padding:.5rem 0;">' +
      '<div style="font-size:.62rem;color:rgba(255,255,255,.3);">Total Units</div>' +
      '<div style="font-size:1.4rem;font-weight:900;color:#6C63FF;">' + totalUnits90 + ' <span style="font-size:.7rem;">' + unit + '</span></div>' +
    '</div></div>' +
    '<div class="col-6 col-md-3"><div style="text-align:center;padding:.5rem 0;">' +
      '<div style="font-size:.62rem;color:rgba(255,255,255,.3);">Total Revenue</div>' +
      '<div style="font-size:1.4rem;font-weight:900;color:#3ECFCF;">' + fmtRs(totalRev90) + '</div>' +
    '</div></div>' +
    '<div class="col-6 col-md-3"><div style="text-align:center;padding:.5rem 0;">' +
      '<div style="font-size:.62rem;color:rgba(255,255,255,.3);">Total Profit</div>' +
      '<div style="font-size:1.4rem;font-weight:900;color:' + (totalProf90 >= 0 ? '#28c76f' : '#ea5455') + ';">' + fmtRs(totalProf90) + '</div>' +
    '</div></div>' +
    '<div class="col-6 col-md-3"><div style="text-align:center;padding:.5rem 0;">' +
      '<div style="font-size:.62rem;color:rgba(255,255,255,.3);">Avg Monthly Profit</div>' +
      '<div style="font-size:1.4rem;font-weight:900;color:#a55eea;">' + fmtRs(totalProf90 / 3) + '</div>' +
    '</div></div>';

  // ── Projected Next Month Row ─────────────────────────────
  document.getElementById('ptProjectedRow').innerHTML =
    '<div class="col-12">' +
      '<div class="glass-section" style="background:rgba(108,99,255,.07);border-color:rgba(108,99,255,.2);">' +
        '<div style="font-size:.65rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.7px;margin-bottom:.7rem;"><i class="bi bi-arrow-right-circle me-1" style="color:#6C63FF;"></i>Projected Next Month (30 Days) based on current growth factor ' + gf.toFixed(2) + '×</div>' +
        '<div class="d-flex gap-3 flex-wrap">' +
          '<div><div style="font-size:.6rem;color:rgba(255,255,255,.28);">Units</div><div style="font-size:1.2rem;font-weight:900;color:#6C63FF;">' + m1q + ' ' + unit + '</div></div>' +
          '<div><div style="font-size:.6rem;color:rgba(255,255,255,.28);">Revenue</div><div style="font-size:1.2rem;font-weight:900;color:#3ECFCF;">' + fmtRsFull(m1r) + '</div></div>' +
          '<div><div style="font-size:.6rem;color:rgba(255,255,255,.28);">Net Profit</div><div style="font-size:1.2rem;font-weight:900;color:' + (m1p >= 0 ? '#28c76f' : '#ea5455') + ';">' + fmtRsFull(m1p) + '</div></div>' +
          '<div><div style="font-size:.6rem;color:rgba(255,255,255,.28);">Margin</div><div style="font-size:1.2rem;font-weight:900;color:' + (margin >= 25 ? '#28c76f' : '#ff9f43') + ';">' + margin.toFixed(1) + '%</div></div>' +
          '<div><div style="font-size:.6rem;color:rgba(255,255,255,.28);">Growth</div><div style="font-size:1.2rem;font-weight:900;color:#a55eea;">' + (m1q > qty30 ? '+' : '') + (qty30 > 0 ? ((m1q - qty30) / qty30 * 100).toFixed(1) : 0) + '%</div></div>' +
        '</div>' +
      '</div>' +
    '</div>';

  // ── Inventory Movement Prediction ───────────────────────
  var daysOfStock = avg7 > 0 ? Math.round(stock / avg7) : (stock > 0 ? 999 : 0);
  var invColor    = daysOfStock < 7 ? '#ea5455' : (daysOfStock < 14 ? '#ff9f43' : '#28c76f');
  var reorderQty  = Math.max(minStock, Math.round(avg30 * 30));
  var invAlert    = daysOfStock <= 0 ? '🔴 Out of Stock — missing revenue now!' :
                    (daysOfStock < 7  ? '🔴 Critical — stock will run out within a week!' :
                    (daysOfStock < 14 ? '🟡 Warning — reorder within the next 7 days' :
                                        '🟢 Stock level is healthy'));
  document.getElementById('ptInventorySection').innerHTML =
    '<div class="d-flex align-items-center gap-2 mb-3">' +
      '<div style="width:32px;height:32px;border-radius:9px;background:' + invColor + '1a;display:flex;align-items:center;justify-content:center;">' +
        '<i class="bi bi-boxes" style="color:' + invColor + ';font-size:.85rem;"></i>' +
      '</div>' +
      '<div style="font-weight:800;font-size:.9rem;color:#fff;">Inventory Movement Prediction</div>' +
    '</div>' +
    '<div class="row g-2">' +
      '<div class="col-6 col-md-3"><div class="glass-tile text-center">' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.28);">Current Stock</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:' + invColor + ';">' + stock + '</div>' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.22);">' + unit + '</div>' +
      '</div></div>' +
      '<div class="col-6 col-md-3"><div class="glass-tile text-center">' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.28);">Days of Stock</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:' + invColor + ';">' + (daysOfStock === 999 ? '∞' : daysOfStock + 'd') + '</div>' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.22);">at current velocity</div>' +
      '</div></div>' +
      '<div class="col-6 col-md-3"><div class="glass-tile text-center">' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.28);">Daily Consumption</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:#6C63FF;">' + avg7.toFixed(1) + '</div>' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.22);">' + unit + '/day</div>' +
      '</div></div>' +
      '<div class="col-6 col-md-3"><div class="glass-tile text-center">' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.28);">Suggested Reorder</div>' +
        '<div style="font-size:1.15rem;font-weight:900;color:#a55eea;">' + reorderQty + '</div>' +
        '<div style="font-size:.6rem;color:rgba(255,255,255,.22);">' + unit + '</div>' +
      '</div></div>' +
    '</div>' +
    '<div style="margin-top:.9rem;padding:.7rem .9rem;background:' + invColor + '0d;border:1px solid ' + invColor + '28;border-radius:11px;font-size:.78rem;color:rgba(255,255,255,.65);">' + invAlert + '</div>' +
    '<div style="margin-top:.5rem;font-size:.65rem;color:rgba(255,255,255,.25);font-style:italic;">WHY: Current stock of ' + stock + ' ' + unit + ' at ' + avg7.toFixed(1) + ' ' + unit + '/day average consumption = ' + (daysOfStock === 999 ? 'infinite stock (no demand detected)' : daysOfStock + ' days remaining') + '.</div>';

  // ── Verdict ──────────────────────────────────────────────
  var vBg = score >= 72 ? 'rgba(40,199,111,.07)'  : (score >= 38 ? 'rgba(255,159,67,.07)' : 'rgba(234,84,85,.07)');
  var vBd = score >= 72 ? 'rgba(40,199,111,.2)'   : (score >= 38 ? 'rgba(255,159,67,.2)'  : 'rgba(234,84,85,.2)');
  var vTx = score >= 72 ? 'Strong Performer — Keep investing in this product'
                        : (score >= 38 ? 'Moderate Performer — Needs strategic attention'
                                       : 'Underperformer — Consider pricing or stock action');
  document.getElementById('ptVerdict').innerHTML =
    '<div style="background:' + vBg + ';border:1px solid ' + vBd + ';border-radius:16px;padding:1.15rem 1.25rem;">' +
      '<div class="d-flex align-items-center gap-2 mb-2">' +
        '<i class="bi bi-robot" style="color:' + clsColor + ';font-size:1.1rem;"></i>' +
        '<span style="color:#fff;font-weight:800;font-size:.95rem;">AI Verdict</span>' +
        '<span class="ai-class-badge ms-auto" style="background:' + clsColor + '1a;color:' + clsColor + ';border:1px solid ' + clsColor + '33;font-size:.68rem;">' + cls + ' · Score ' + score + '/100</span>' +
      '</div>' +
      '<div style="color:rgba(255,255,255,.75);font-size:.85rem;font-weight:600;">' + vTx + '</div>' +
      '<div style="color:rgba(255,255,255,.35);font-size:.72rem;margin-top:.5rem;">' +
        'Win probability <b style="color:' + (win >= 65 ? '#28c76f' : (win >= 45 ? '#ff9f43' : '#ea5455')) + ';">' + win + '%</b> · ' +
        'Confidence <b style="color:#3ECFCF;">' + confidence + '%</b> · ' +
        'Risk level <b style="color:' + riskColor + ';">' + riskLabel + '</b> · ' +
        'Growth factor <b style="color:#a55eea;">' + gf.toFixed(2) + '×</b>' +
      '</div>' +
    '</div>';

  // ── AI Suggestions ───────────────────────────────────────
  var suggs = [];

  // Margin suggestions
  if (cost > retail && retail > 0) {
    suggs.push({ icon: 'exclamation-octagon-fill', color: '#ea5455', title: '🔴 CRITICAL: Selling Below Cost!', body: 'Your selling price Rs.' + retail + ' is lower than cost Rs.' + cost + '. Every sale loses you Rs.' + Math.round(cost - retail) + '. Raise price immediately to at least Rs.' + Math.round(cost * 1.25) + ' for 25% margin.', why: 'Margin is negative — you are losing money on every unit sold.' });
  } else if (margin < 10 && cost > 0) {
    suggs.push({ icon: 'exclamation-triangle-fill', color: '#ea5455', title: '⚠️ Very Low Margin (' + margin.toFixed(1) + '%)', body: 'Suggested price for 20% margin: Rs.' + Math.round(cost / 0.80 / 5) * 5 + '. Current margin is too thin to cover overheads.', why: 'Low margin products are vulnerable to any cost increase and generate minimal profit per sale.' });
  } else if (margin >= 35) {
    suggs.push({ icon: 'graph-up-arrow', color: '#28c76f', title: '💚 Excellent Margin (' + margin.toFixed(1) + '%) — Prioritize This!', body: 'Consider marketing this product aggressively. With ' + margin.toFixed(1) + '% margin, more sales = more profit. Upsell, bundle, and promote.', why: 'High-margin products generate the most profit per unit sold.' });
  }

  // Stock suggestions
  if (stock <= 0 && qty30 > 5) {
    suggs.push({ icon: 'box-seam', color: '#ff9f43', title: '📦 Out of Stock — Lost Revenue Alert!', body: 'You are selling ~' + avg30.toFixed(1) + ' ' + unit + '/day but stock is zero. Reorder at least ' + reorderQty + ' ' + unit + ' immediately. Estimated daily revenue loss: ' + fmtRsFull(avg30 * retail) + '.', why: 'Out-of-stock products lose revenue every day and damage customer trust.' });
  } else if (daysOfStock < 7 && daysOfStock > 0) {
    suggs.push({ icon: 'exclamation-circle-fill', color: '#ff9f43', title: '⚠️ Low Stock — Order in ' + daysOfStock + ' Days!', body: 'At current consumption rate, stock will run out in ' + daysOfStock + ' days. Place reorder now for ' + reorderQty + ' ' + unit + ' to avoid stockout.', why: 'Stock cover below 7 days creates risk of revenue loss.' });
  } else if (qty30 === 0 && stock > 10) {
    suggs.push({ icon: 'archive-fill', color: '#ff9f43', title: '📦 Dead Stock — No Sales in 30 Days!', body: 'Rs.' + Math.round(stock * cost).toLocaleString() + ' capital is locked in unsold inventory. Consider a 10–15% discount, create a bundle, or place near checkout for visibility.', why: 'Idle inventory ties up capital and generates zero return.' });
  }

  // Velocity suggestions
  if (velRatio >= 1.5) {
    suggs.push({ icon: 'rocket-takeoff-fill', color: '#28c76f', title: '🚀 Sales Accelerating!', body: 'This week\'s sales are ' + velRatio.toFixed(1) + '× the monthly average — strong upward momentum. Increase stock, ensure availability, and consider upselling.', why: 'Rising velocity (>1.5×) indicates strong demand growth that should be capitalized on.' });
  } else if (velRatio < 0.5 && qty30 > 0) {
    suggs.push({ icon: 'graph-down-arrow', color: '#ea5455', title: '📉 Declining Sales Velocity', body: 'Sales this week are only ' + (velRatio * 100).toFixed(0) + '% of the monthly average. Try promotions, price revision, or social media push to revive demand.', why: 'Velocity below 0.5× suggests customers are moving away from this product.' });
  }

  // Profit suggestions
  if (profit30 < 0) {
    suggs.push({ icon: 'graph-down-arrow', color: '#ea5455', title: '🔴 Net LOSS in Last 30 Days!', body: 'This product generated a loss of Rs.' + Math.round(Math.abs(profit30)).toLocaleString() + ' in the last 30 days. Immediate pricing review required.', why: 'Negative profit means selling is hurting, not helping your business.' });
  }

  // Growth suggestion
  if (score >= 72 && velRatio >= 0.8) {
    suggs.push({ icon: 'lightning-charge-fill', color: '#3ECFCF', title: '⚡ High Growth Potential', body: 'This product scores ' + score + '/100 — strong candidate for bulk purchasing, promotional featured placement, and monthly offer inclusion.', why: 'Top-scored products with good velocity are the best candidates for growth investment.' });
  }

  // Break-even suggestion
  if (beUnits > 0 && qty30 < beUnits) {
    suggs.push({ icon: 'calculator', color: '#a55eea', title: '📊 Not Reaching Break-Even Volume', body: 'Break-even is ' + beUnits + ' ' + unit + '/month but you sold only ' + qty30 + '. Need ' + (beUnits - qty30) + ' more units to cover costs. Push marketing or reduce cost.', why: 'Products below break-even quantity contribute negatively to monthly fixed cost recovery.' });
  }

  // Default if no suggestions
  if (suggs.length === 0) {
    suggs.push({ icon: 'check-circle-fill', color: '#28c76f', title: '✅ Product is Performing Well', body: 'No immediate concerns detected. Continue monitoring weekly. Consider promotional bundling to push volume further.', why: 'All key metrics (margin, velocity, stock, profit) are within acceptable ranges.' });
  }

  document.getElementById('ptSuggestions').innerHTML = suggs.map(function(s) {
    return '<div class="pt-sugg">' +
      '<div class="pt-sugg-icon" style="background:' + s.color + '1a;">' +
        '<i class="bi bi-' + s.icon + '" style="color:' + s.color + ';font-size:.85rem;"></i>' +
      '</div>' +
      '<div style="flex:1;">' +
        '<div style="color:#fff;font-weight:700;font-size:.84rem;margin-bottom:.25rem;">' + s.title + '</div>' +
        '<div style="color:rgba(255,255,255,.55);font-size:.77rem;line-height:1.5;margin-bottom:.25rem;">' + s.body + '</div>' +
        '<div style="font-size:.68rem;color:rgba(255,255,255,.28);font-style:italic;"><i class="bi bi-info-circle me-1"></i>WHY: ' + s.why + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// ── Tab 2 — Intelligence Filter ──────────────────────────────
function filterByClass(cls) {
  var items = document.querySelectorAll('.intel-prod-item');
  var label = document.getElementById('classFilterLabel');
  var name  = document.getElementById('classFilterName');
  items.forEach(function(item) {
    if (cls === 'all' || item.dataset.class === cls) {
      item.style.display = 'block';
    } else {
      item.style.display = 'none';
    }
  });
  if (cls === 'all') {
    label.style.display = 'none';
  } else {
    label.style.display = 'block';
    name.textContent = cls;
  }
  // Highlight active class card
  document.querySelectorAll('.prod-class-card').forEach(function(c) {
    c.style.outline = c.dataset.class === cls ? '2px solid rgba(108,99,255,.6)' : 'none';
    c.style.boxShadow = c.dataset.class === cls ? '0 0 0 3px rgba(108,99,255,.15)' : '';
  });
}

// ── Tab 3 — Price Recs Filter ────────────────────────────────
function filterPriceRecs(action, btn) {
  document.querySelectorAll('.filter-btn').forEach(function(b) {
    if (b.closest('#sec-price_opt')) b.classList.remove('active');
  });
  if (btn) btn.classList.add('active');
  document.querySelectorAll('#priceRecList .price-row').forEach(function(row) {
    row.style.display = (action === 'all' || row.dataset.action === action) ? '' : 'none';
  });
}

// ── Tab 4 — Demand Filter ────────────────────────────────────
function filterDemand(forecast, btn) {
  document.querySelectorAll('#sec-demand .filter-btn').forEach(function(b) { b.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  document.querySelectorAll('#demandList .demand-row').forEach(function(row) {
    row.style.display = (forecast === 'all' || row.dataset.forecast === forecast) ? '' : 'none';
  });
}

// ── Tab 6 — AI Business Advisor ──────────────────────────────
var advisorData = <?php
  $adData = [
    'totalProducts'  => count($allProducts),
    'rev30'          => (float)$shopStats['rev30'],
    'prof30'         => (float)$shopStats['prof30'],
    'margin30'       => (float)$shopStats['margin30'],
    'exp30'          => (float)$shopStats['exp30'],
    'txn30'          => (int)$shopStats['txn30'],
    'bestSellers'    => $bestSellers,
    'growthProds'    => $growthProds,
    'riskProds'      => $riskProds,
    'lowProds'       => $lowProds,
    'highDemand'     => $highCount,
    'deadDemand'     => $deadCount,
    'criticalLoss'   => $critCount,
    'raiseCount'     => $raiseCount,
    'lowerCount'     => $lowerCount,
    'totalExtra'     => $totalExtra,
    'topProducts'    => array_slice(array_map(fn($p) => ['name'=>$p['name'],'qty30'=>(int)$p['qty_30d'],'margin'=>$p['retail_price']>0?round(($p['retail_price']-$p['company_price'])/$p['retail_price']*100,1):0,'profit30'=>round((float)$p['profit_30d'])], $allProducts), 0, 5),
    'weakProducts'   => array_values(array_slice(array_map(fn($p) => $p['name'], array_filter($allProducts, fn($p) => classifyProduct($p)['score'] < 38)), 0, 5)),
    'discontinue'    => array_values(array_slice(array_map(fn($p) => $p['name'], array_filter($allProducts, fn($p) => (int)$p['qty_30d'] === 0 && (int)$p['stock_quantity'] > 0)), 0, 4)),
    'netProfit30'    => (float)$shopStats['prof30'] - (float)$shopStats['exp30'],
  ];
  echo json_encode($adData);
?>;
var chatCount = 0;

function askAdvisor(q) {
  q = q ? q.trim() : '';
  if (!q) return;
  var input = document.getElementById('advisorInput');
  if (input) input.value = '';
  chatCount++;
  var hist = document.getElementById('advisorHistory');

  // User bubble
  var uDiv = document.createElement('div');
  uDiv.className = 'mb-3';
  uDiv.innerHTML = '<div class="d-flex justify-content-end mb-1"><div class="advisor-q-bubble">' + q.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div></div>';
  hist.appendChild(uDiv);

  // Typing indicator
  var tDiv = document.createElement('div');
  tDiv.className = 'advisor-bubble advisor-typing mb-3';
  tDiv.innerHTML = '<span></span><span></span><span></span>';
  hist.appendChild(tDiv);
  hist.scrollTop = hist.scrollHeight;

  // Generate response after delay
  setTimeout(function() {
    tDiv.remove();
    var resp = generateAdvisorResponse(q, advisorData);
    var rDiv = document.createElement('div');
    rDiv.className = 'advisor-bubble mb-3';
    rDiv.innerHTML = resp;
    hist.appendChild(rDiv);
    hist.scrollTop = hist.scrollHeight;
  }, 850);
}

function generateAdvisorResponse(q, d) {
  var ql = q.toLowerCase();

  // ── profit / revenue ──────────────────────────────────
  if (/profit|revenue|income|earn|money|margin/.test(ql)) {
    var netP = d.netProfit30;
    var health = netP > 0 ? 'healthy' : 'in the negative zone';
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">📊 Profit & Revenue Analysis</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '• 30-day revenue: <b style="color:#3ECFCF;">Rs.' + d.rev30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Gross profit: <b style="color:#28c76f;">Rs.' + d.prof30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Expenses: <b style="color:#ff9f43;">Rs.' + d.exp30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Net profit: <b style="color:' + (netP >= 0 ? '#28c76f' : '#ea5455') + ';">Rs.' + netP.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Overall margin: <b style="color:#6C63FF;">' + d.margin30 + '%</b>' +
      '</div>' +
      '<div style="background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.18);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Your net profit is <b>' + health + '</b>. ' +
      (d.raiseCount > 0 ? 'Raising prices for ' + d.raiseCount + ' products could add Rs.' + d.totalExtra.toLocaleString() + '/month to your bottom line.' : 'Your pricing appears well-optimized.') +
      '</div>';
  }

  // ── stock / inventory ──────────────────────────────────
  if (/stock|inventory|reorder|supply|warehouse/.test(ql)) {
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">📦 Stock & Inventory Intelligence</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '• <b style="color:#ea5455;">' + d.deadDemand + '</b> products have <b>zero demand</b> with stock sitting idle<br>' +
      '• <b style="color:#ff9f43;">' + d.criticalLoss + '</b> products have critical stock/pricing issues<br>' +
      '• <b>' + d.totalProducts + '</b> total active products in your catalog' +
      '</div>' +
      '<div style="background:rgba(255,159,67,.07);border:1px solid rgba(255,159,67,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Focus: Clear dead stock with discounts, ensure fast-moving products never run out. Use the Demand Forecast tab for specific product stock days.' +
      '</div>';
  }

  // ── slow / dead products ──────────────────────────────
  if (/slow|dead|not selling|zero sales|no sales|discontinue|remove/.test(ql)) {
    var dList = d.discontinue && d.discontinue.length > 0 ? d.discontinue.join(', ') : 'none identified';
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">❌ Slow & Dead Products</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '• <b style="color:#ea5455;">' + d.deadDemand + '</b> products with zero sales in 30 days<br>' +
      '• <b style="color:#ff9f43;">' + d.lowProds + '</b> products classified as Low Performers<br>' +
      '• Products to consider discontinuing: <b style="color:#ff9f43;">' + dList + '</b>' +
      '</div>' +
      '<div style="background:rgba(234,84,85,.07);border:1px solid rgba(234,84,85,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Strategy: Apply 10–15% discount first. If no movement in 2 weeks, bundle with fast sellers. Last resort: remove from shelf to free capital.' +
      '</div>';
  }

  // ── best sellers ──────────────────────────────────────
  if (/best|top|highest|popular|most sold|selling most/.test(ql)) {
    var topList = d.topProducts && d.topProducts.length > 0
      ? d.topProducts.map(function(p, i) { return '• <b style="color:#28c76f;">#' + (i+1) + ' ' + p.name + '</b> — ' + p.qty30 + ' units/30d · ' + p.margin + '% margin · Profit Rs.' + p.profit30.toLocaleString(); }).join('<br>')
      : '• No sales data available yet';
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">🏆 Top Selling Products</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' + topList + '</div>' +
      '<div style="background:rgba(40,199,111,.07);border:1px solid rgba(40,199,111,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 You have <b>' + d.bestSellers + '</b> Best Sellers and <b>' + d.growthProds + '</b> Growth Products. Keep these always in stock and consider upselling them.' +
      '</div>';
  }

  // ── discontinue ───────────────────────────────────────
  if (/stop|discontinue|drop|remove product|kill/.test(ql)) {
    var dList2 = d.discontinue && d.discontinue.length > 0 ? d.discontinue.join(', ') : 'none at critical level';
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">🗑️ Discontinuation Candidates</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      'Products with zero sales + existing stock that should be cleared:<br><b style="color:#ff9f43;">' + dList2 + '</b><br><br>' +
      'Also consider: <b>' + d.lowProds + '</b> Low Performers with very weak metrics.' +
      '</div>' +
      '<div style="background:rgba(255,159,67,.07);border:1px solid rgba(255,159,67,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Before discontinuing: Try 15% discount → bundle deal → last-chance sale. Only discontinue if all else fails.' +
      '</div>';
  }

  // ── strategy ──────────────────────────────────────────
  if (/strategy|plan|weekly|monthly|focus|action|improve/.test(ql)) {
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">🎯 Weekly Business Strategy</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '<b>This Week\'s Action Plan:</b><br>' +
      '1. 📦 Reorder stock for your top ' + Math.min(d.bestSellers, 5) + ' Best Sellers before they run out<br>' +
      '2. 💰 Raise prices for ' + d.raiseCount + ' underpriced products (see Smart Price tab)<br>' +
      '3. 🏷️ Apply discounts to clear ' + d.deadDemand + ' zero-demand products<br>' +
      '4. 📊 Focus marketing budget on ' + d.growthProds + ' Growth Products<br>' +
      '5. 🔴 Fix ' + d.criticalLoss + ' critical loss alerts immediately' +
      '</div>' +
      '<div style="background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.18);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Business health tip: Maintain 20%+ margin across all products, keep fast sellers stocked, and clear dead inventory monthly.' +
      '</div>';
  }

  // ── expenses ──────────────────────────────────────────
  if (/expense|cost|cut|reduce|overhead|spend/.test(ql)) {
    var expRatio = d.rev30 > 0 ? ((d.exp30 / d.rev30) * 100).toFixed(1) : 0;
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">✂️ Expense Reduction Strategy</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '• 30-day expenses: <b style="color:#ff9f43;">Rs.' + d.exp30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Expense-to-revenue ratio: <b style="color:' + (expRatio < 15 ? '#28c76f' : (expRatio < 25 ? '#ff9f43' : '#ea5455')) + ';">' + expRatio + '%</b><br>' +
      '• Net after expenses: <b style="color:' + (d.netProfit30 >= 0 ? '#28c76f' : '#ea5455') + ';">Rs.' + d.netProfit30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b>' +
      '</div>' +
      '<div style="background:rgba(255,159,67,.07);border:1px solid rgba(255,159,67,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Healthy expense ratio is below 20% of revenue. If higher, review recurring costs, negotiate supplier prices, and reduce wastage from dead stock.' +
      '</div>';
  }

  // ── most profitable ───────────────────────────────────
  if (/profitable|profit margin|high margin|best profit/.test(ql)) {
    var highM = d.topProducts && d.topProducts.length > 0
      ? d.topProducts.slice().sort(function(a, b) { return b.margin - a.margin; }).slice(0, 3).map(function(p) { return '<b style="color:#28c76f;">' + p.name + '</b> (' + p.margin + '%)'; }).join(', ')
      : 'N/A';
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">💎 Most Profitable Products</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      'Top margin products: ' + highM + '<br><br>' +
      '• <b>' + d.bestSellers + '</b> Best Sellers generating consistent high profit<br>' +
      '• Overall shop margin: <b style="color:#6C63FF;">' + d.margin30 + '%</b>' +
      '</div>' +
      '<div style="background:rgba(40,199,111,.07);border:1px solid rgba(40,199,111,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Focus on selling more high-margin products. Even a small volume push on 30%+ margin items significantly improves net profit.' +
      '</div>';
  }

  // ── growth potential ──────────────────────────────────
  if (/growth|grow|potential|opportunity|expand|scale/.test(ql)) {
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">🚀 Growth Opportunities</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '• <b style="color:#3ECFCF;">' + d.growthProds + '</b> products classified as Growth Products — rising demand<br>' +
      '• <b style="color:#28c76f;">' + d.highDemand + '</b> products showing high demand acceleration this week<br>' +
      '• Price optimization can add <b style="color:#ff9f43;">+Rs.' + d.totalExtra.toLocaleString() + '/month</b> to revenue' +
      '</div>' +
      '<div style="background:rgba(62,207,207,.07);border:1px solid rgba(62,207,207,.2);border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 Invest in Growth Products now — increase stock, promote them, and consider bundle offers. These are your rising stars with the best momentum.' +
      '</div>';
  }

  // ── health report ─────────────────────────────────────
  if (/health|report|overall|summary|overview|status/.test(ql)) {
    var healthScore = Math.min(100, Math.max(0,
      (d.margin30 >= 25 ? 25 : d.margin30 >= 15 ? 15 : 5) +
      (d.bestSellers >= 3 ? 25 : d.bestSellers >= 1 ? 15 : 0) +
      (d.criticalLoss === 0 ? 25 : d.criticalLoss <= 2 ? 10 : 0) +
      (d.netProfit30 > 0 ? 25 : d.netProfit30 > -5000 ? 10 : 0)
    ));
    var hColor = healthScore >= 70 ? '#28c76f' : (healthScore >= 45 ? '#ff9f43' : '#ea5455');
    return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">📊 Overall Business Health Report</div>' +
      '<div style="text-align:center;margin:.8rem 0;">' +
        '<div style="font-size:2.5rem;font-weight:900;color:' + hColor + ';">' + healthScore + '<span style="font-size:1rem;color:rgba(255,255,255,.35);">/100</span></div>' +
        '<div style="font-size:.78rem;color:rgba(255,255,255,.4);">Business Health Score</div>' +
      '</div>' +
      '<div style="color:rgba(255,255,255,.7);font-size:.82rem;line-height:1.7;">' +
      '• Revenue 30d: <b style="color:#3ECFCF;">Rs.' + d.rev30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Net Profit: <b style="color:' + (d.netProfit30 >= 0 ? '#28c76f' : '#ea5455') + ';">Rs.' + d.netProfit30.toLocaleString('en-IN', {maximumFractionDigits:0}) + '</b><br>' +
      '• Best Sellers: <b style="color:#28c76f;">' + d.bestSellers + '</b> · Growth: <b style="color:#3ECFCF;">' + d.growthProds + '</b> · Risk: <b style="color:#ea5455;">' + d.riskProds + '</b><br>' +
      '• Critical Alerts: <b style="color:#ea5455;">' + d.criticalLoss + '</b> · Margin: <b style="color:#6C63FF;">' + d.margin30 + '%</b>' +
      '</div>' +
      '<div style="background:' + hColor + '0d;border:1px solid ' + hColor + '28;border-radius:10px;padding:.6rem .8rem;margin-top:.8rem;font-size:.78rem;color:rgba(255,255,255,.6);">' +
      '💡 ' + (healthScore >= 70 ? 'Your business is in good health! Keep maintaining stock for best sellers and optimize prices on remaining products.' :
               healthScore >= 45 ? 'Business is moderate — focus on fixing ' + d.criticalLoss + ' critical alerts, clearing dead stock, and improving low-margin products.' :
                                   'Business needs urgent attention — address critical losses, fix pricing issues, and revive stalled products immediately.') +
      '</div>';
  }

  // ── default fallback ──────────────────────────────────
  return '<div style="color:#fff;font-weight:700;font-size:.9rem;margin-bottom:.6rem;">🤖 AI Business Advisor</div>' +
    '<div style="color:rgba(255,255,255,.65);font-size:.82rem;line-height:1.7;">' +
    'I can help you with:<br>' +
    '• <span style="cursor:pointer;color:#6C63FF;" onclick="askAdvisor(\'How is my profit?\')">📊 Profit & Revenue analysis</span><br>' +
    '• <span style="cursor:pointer;color:#6C63FF;" onclick="askAdvisor(\'Tell me about my stock\')">📦 Stock & Inventory guidance</span><br>' +
    '• <span style="cursor:pointer;color:#6C63FF;" onclick="askAdvisor(\'Which products are best selling?\')">🏆 Best selling products</span><br>' +
    '• <span style="cursor:pointer;color:#6C63FF;" onclick="askAdvisor(\'What is my weekly strategy?\')">🎯 Weekly strategy plan</span><br>' +
    '• <span style="cursor:pointer;color:#6C63FF;" onclick="askAdvisor(\'Give me overall business health report\')">💎 Full health report</span><br>' +
    '• <span style="cursor:pointer;color:#6C63FF;" onclick="askAdvisor(\'What products have growth potential?\')">🚀 Growth opportunities</span>' +
    '</div>' +
    '<div style="background:rgba(108,99,255,.07);border:1px solid rgba(108,99,255,.15);border-radius:10px;padding:.5rem .8rem;margin-top:.7rem;font-size:.74rem;color:rgba(255,255,255,.4);">Try asking in natural language — e.g. "How can I improve my margin?"</div>';
}

// ── Tab 7 — Auto Tags Filter ─────────────────────────────────
function filterTag(tag, clickedEl) {
  var items = document.querySelectorAll('.tag-prod-item');
  var label = document.getElementById('tagActiveLabel');
  var name  = document.getElementById('tagActiveName');

  items.forEach(function(item) {
    if (tag === 'all') {
      item.style.display = 'block';
    } else {
      var tags = item.dataset.tags ? item.dataset.tags.split('|') : [];
      item.style.display = tags.indexOf(tag) !== -1 ? 'block' : 'none';
    }
  });

  // Update label
  if (tag === 'all') {
    label.style.display = 'none';
  } else {
    label.style.display = 'block';
    name.textContent = tag;
  }

  // Visual feedback on clicked card
  document.querySelectorAll('.tag-filter-card').forEach(function(c) {
    c.style.outline = '';
    c.style.boxShadow = '';
  });
  if (clickedEl && tag !== 'all') {
    clickedEl.style.outline = '2px solid rgba(108,99,255,.5)';
    clickedEl.style.boxShadow = '0 0 0 3px rgba(108,99,255,.12)';
  }
}

// ── Init: shopTrendChart on load if demand tab is active ──────
window.addEventListener('load', function() {
  // Auto-init shop trend chart only if demand section is already active
  var demandSec = document.getElementById('sec-demand');
  if (demandSec && demandSec.classList.contains('active')) {
    window._shopTrendInited = true;
    initShopTrendChart();
  }
});
</script>

<?php shopFooter(); ?>
