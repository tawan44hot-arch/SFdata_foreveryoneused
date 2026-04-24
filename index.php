<?php

ini_set('display_errors', 1); // เปิดแจ้งเตือน Error เพื่อหาจุดเสีย
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// --- 1. Logic: Upsert (Insert or Update) ---
if (isset($_POST['add_data'])) {
    $Modelname = mysqli_real_escape_string($conn, $_POST['Modelname']);
    $BiosVer = mysqli_real_escape_string($conn, $_POST['BiosVer']);
    $Testimage = mysqli_real_escape_string($conn, $_POST['Testimage']);
    $ssid = mysqli_real_escape_string($conn, $_POST['ssid']);
    $user = $_SESSION['username'];

    $custom_date = mysqli_real_escape_string($conn, $_POST['custom_date']);

    $check = $conn->query("SELECT id FROM Model WHERE Modelname = '$Modelname'");

    if ($check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $m_id = $row['id'];
        $sql = "UPDATE Model SET BiosVer='$BiosVer', Testimage='$Testimage', ssid='$ssid', updated_by='$user' WHERE id=$m_id";
        $msg = "อัปเดตข้อมูลรุ่น $Modelname สำเร็จ";
    } else {
        $sql = "INSERT INTO Model (Modelname, BiosVer, Testimage, ssid, updated_by, updated_at) VALUES ('$Modelname', '$BiosVer', '$Testimage','$ssid', '$user', '$custom_date')";
        $msg = "เพิ่มโมเดลใหม่ $Modelname สำเร็จ";
    }

    if ($conn->query($sql)) {
        $target_id = ($check->num_rows > 0) ? $m_id : $conn->insert_id;
        $conn->query("INSERT INTO Model_History (model_id, Modelname, BiosVer, Testimage, ssid, updated_by, updated_at, category) 
                      VALUES ('$target_id', '$Modelname', '$BiosVer', '$Testimage', '$ssid', '$user', '$custom_date', 'General Update')");

        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
                <script>
                    setTimeout(function() {
                        Swal.fire({ icon: 'success', title: 'สำเร็จ!', text: '$msg', confirmButtonColor: '#28a745' })
                        .then(() => { window.location.href='index.php'; });
                    }, 100);
                </script>";
    }
}

// --- 2. Logic: ลบข้อมูล และ Reset ID ---
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $res = $conn->query("SELECT Modelname FROM Model WHERE id = $id");
    $data = $res->fetch_assoc();
    $m_name = $data['Modelname'] ?? "";

    if ($conn->query("DELETE FROM Model WHERE id = $id")) {
        $conn->query("SET @num := 0;");
        $conn->query("UPDATE Model SET id = (@num := @num + 1);");
        $conn->query("ALTER TABLE Model AUTO_INCREMENT = 1;");
        header("Location: index.php?status=deleted&name=" . urlencode($m_name));
        exit();
    }
}

// --- 3. Logic: Export Excel (ฉบับแก้ไขให้รองรับการค้นหา) ---
if (isset($_GET['export'])) {
    $search_export = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : "";

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=Model_Search_Result_" . date('Ymd') . ".xls");

    echo '<table border="1"><tr><th>ID</th><th>Model</th><th>BIOS</th><th>Image</th><th>ssid</th><th>User</th><th>Date</th></tr>';

    $sql_export = "SELECT * FROM Model";
    if ($search_export != "") {
        $sql_export .= " WHERE Modelname LIKE '%$search_export%'";
    }
    $sql_export .= " ORDER BY Modelname ASC";

    $res = $conn->query($sql_export);
    while ($r = $res->fetch_assoc()) {
        echo "<tr>
                    <td>{$r['id']}</td>
                    <td>{$r['Modelname']}</td>
                    <td>{$r['BiosVer']}</td>
                    <td>{$r['Testimage']}</td>
                    <td>{$r['ssid']}</td>
                    <td>{$r['updated_by']}</td>
                    <td>{$r['updated_at']}</td>
                </tr>";
    }
    echo '</table>';
    exit();
}

// --- 4. Logic: ค้นหาและการแบ่งหน้า (Pagination) ---
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : "";
$limit = 10;
$page = isset($_GET['p']) ? intval($_GET['p']) : 1;
if ($page < 1)
    $page = 1;
$start = ($page - 1) * $limit;

$total_sql = "SELECT COUNT(*) FROM Model";
if ($search != "")
    $total_sql .= " WHERE Modelname LIKE '%$search%'";
$total_res = $conn->query($total_sql);
$total_rows = $total_res->fetch_row()[0];
$total_pages = ceil($total_rows / $limit);

// --- Logic: จัดการ Tracking ---
if (isset($_POST['action_tracking'])) {
    $m_id = intval($_POST['modal_m_id']);
    $m_name = mysqli_real_escape_string($conn, $_POST['model_name']);
    $rtm = !empty($_POST['modal_rtm']) ? "'" . mysqli_real_escape_string($conn, $_POST['modal_rtm']) . "'" : "NULL";
    $rtp = !empty($_POST['modal_rtp']) ? "'" . mysqli_real_escape_string($conn, $_POST['modal_rtp']) . "'" : "NULL";
    $rem = mysqli_real_escape_string($conn, $_POST['modal_remark']);
    $user = $_SESSION['username'];

    if (isset($_POST['complete_tracking'])) {
        $current_res = $conn->query("SELECT BiosVer, Testimage, ssid FROM Model WHERE id = $m_id");
        $current_data = $current_res->fetch_assoc();

        $bios = mysqli_real_escape_string($conn, $current_data['BiosVer']);
        $img = mysqli_real_escape_string($conn, $current_data['Testimage']);
        $sid = mysqli_real_escape_string($conn, $current_data['ssid']);

        $sql_history = "INSERT INTO Model_History (Modelname, BiosVer, Testimage, ssid, rtm_date, rtp_date, remark, updated_by, updated_at, category) 
                        VALUES ('$m_name', '$bios', '$img', '$sid', $rtm, $rtp, '$rem', '$user', NOW(), 'Tracking Complete')";

        if ($conn->query($sql_history)) {
            $conn->query("UPDATE Model SET rtm_date=NULL, rtp_date=NULL, remark=NULL WHERE id=$m_id");
            $status_msg = "ดำเนินการสำเร็จ!";
        }
    } elseif (isset($_POST['clear_tracking'])) {
        $conn->query("UPDATE Model SET rtm_date=NULL, rtp_date=NULL, remark=NULL WHERE id=$m_id");
        $status_msg = "ล้างข้อมูลแล้ว";
    } else {
        $conn->query("UPDATE Model SET rtm_date=$rtm, rtp_date=$rtp, remark='$rem' WHERE id=$m_id");
        $status_msg = "อัปเดตข้อมูลแล้ว";
    }
    echo "<script>window.location.href='index.php';</script>";
    exit();
}

?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>Model DB Online</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Kanit', sans-serif;
            margin: 0;
            background: #f0f2f5;
        }

        .nav {
            background-image: linear-gradient(rgba(255, 255, 255, 0.8), rgba(255, 255, 255, 0.8)), url('https://cdn-icons-png.flaticon.com/512/1056/1056135.png');
            background-size: cover;
            background-position: center;
            padding: 10px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .db-logo {
            width: 50px;
            height: auto;
            transition: 0.3s;
        }

        .db-logo:hover {
            transform: scale(1.1);
        }

        .logo-container strong {
            font-size: 24px;
            color: #007bff;
            letter-spacing: 1px;
        }

        .hero {
            background: #007bff;
            color: white;
            padding: 40px;
            text-align: center;
        }

        .container {
            max-width: 1100px;
            margin: -30px auto 50px;
            padding: 0 20px;
        }

        .box {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            gap: 10px;
        }

        input[type="text"] {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            flex: 1;
            font-family: 'Kanit';
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            font-family: 'Kanit';
        }

        .btn-green {
            background: #28a745;
        }

        .btn-blue {
            background: #007bff;
        }

        .btn-purple {
            background: #6f42c1;
        }

        .btn-cyan {
            background: #17a2b8;
        }

        .btn-delete {
            background-color: #dc3545;
            color: white;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
            transition: 0.3s;
        }

        .btn-delete:hover {
            background-color: #a71d2a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f8f9fa;
        }

        .btn-page {
            padding: 8px 15px;
            background: white;
            border: 1px solid #ddd;
            color: #007bff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            transition: 0.2s;
        }

        .btn-page:hover {
            background: #f0f7ff;
        }

        .btn-page.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        input[type="text"],
        input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Kanit', sans-serif;
            font-size: 14px;
            box-sizing: border-box;
            height: 42px;
        }

        .input-item {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 180px;
            margin-bottom: 10px;
        }

        /* Clock Widget Styles */
        .quick-clock {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 20px;
            color: white;
            text-align: center;
            margin-bottom: 20px;
        }

        .quick-clock h3 {
            margin: 0 0 10px 0;
            font-size: 1.2rem;
        }

        .quick-clock-time {
            font-size: 2.2rem;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            margin: 10px 0;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
        }

        .quick-clock-info {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .nav-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .nav {
                flex-direction: column;
                gap: 15px;
            }

            .nav-buttons {
                width: 100%;
            }

            .btn {
                padding: 8px 16px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>

    <div class="nav">
        <div class="logo-container">
            <a href="index.php" style="text-decoration: none; display: flex; align-items: center; gap: 10px;">
                <img src="https://cdn-icons-png.flaticon.com/512/8637/8637124.png" class="db-logo" alt="Logo">
                <strong style="color:#007bff; font-size: 24px; letter-spacing: 1px;">SFData System</strong>
            </a>
        </div>

        <div class="nav-buttons">
            <a href="digital-clock.html" class="btn btn-cyan">🌍 World Clock</a>
            <a href="calendar.php" class="btn btn-blue">📅 ปฏิทิน</a>
            <a href="history.php" class="btn btn-purple">📜 ประวัติทั้งหมด</a>
            <a href="logout.php"
                style="color:#dc3545; text-decoration: none; font-weight: bold;">ออกจากระบบ</a>
        </div>
    </div>

    <div class="hero">
        <h1>ระบบจัดการข้อมูลโมเดล</h1>
        <p>By Sunnny🌞🌻</p>
    </div>

    <div class="container">
        <!-- Quick Clock Widget -->
        <div class="quick-clock">
            <h3>🕐 เวลาปัจจุบัน</h3>
            <div class="quick-clock-time" id="currentTime">--:--:--</div>
            <div class="quick-clock-info" id="currentDate">-</div>
            <div class="quick-clock-info" id="currentTimezone">-</div>
            <a href="digital-clock.html" style="color: white; margin-top: 10px; display: inline-block; text-decoration: underline;">ดูเวลาในเขตเวลาอื่น →</a>
        </div>

        <div class="box">
            <h3>➕ บันทึกข้อมูล</h3>
            <form method="POST" class="form-group">
                <input type="text" name="Modelname" placeholder="ชื่อโมเดล" required>
                <input type="text" name="BiosVer" placeholder="เวอร์ชัน BIOS" required>
                <input type="text" name="Testimage" placeholder="ชื่อไฟล์ Image" required>
                <input type="text" name="ssid" placeholder="เลข ssid" required>

                <div style="display:flex; flex-direction:column; gap:2px;">
                    <input type="date" name="custom_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <button type="submit" name="add_data" class="btn btn-green">บันทึก</button>
            </form>
        </div>

        <div class="box">
            <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <form method="GET" style="display:flex; gap:5px;">
                    <input type="text" name="search" placeholder="ค้นหา..."
                        value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-blue">ค้นหา</button>
                </form>
                <a href="index.php?export=1&search=<?php echo urlencode($search); ?>" class="btn btn-green">📊 Export Excel</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Model Name</th>
                        <th>Latest BIOS</th>
                        <th>Latest Image</th>
                        <th>Last ssid</th>
                        <th>Cut-in date</th>
                        <th>DRI</th>
                        <th>RTP Status</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>

                <tbody>
                    <?php
                    $today = new DateTime();
                    $sql = "SELECT * FROM Model";
                    if ($search != "") {
                        $sql .= " WHERE Modelname LIKE '%$search%'";
                    }
                    $sql .= " ORDER BY id ASC LIMIT $start, $limit";
                    $res = $conn->query($sql);

                    while ($row = $res->fetch_assoc()):
                        $days_info = "<span style='color:#999;'>ไม่ได้ตั้งค่า</span>";
                        if (!empty($row['rtp_date'])) {
                            $rtp = new DateTime($row['rtp_date']);
                            $rtp->setTime(0, 0);
                            $today_dt = new DateTime();
                            $today_dt->setTime(0, 0);

                            $interval = $today_dt->diff($rtp);
                            $days_left = (int) $interval->format("%r%a");

                            if ($days_left < 0) {
                                $overdue = abs($days_left);
                                $days_info = "<span style='color:#dc3545; font-weight:bold;'>⚠️ ล่าช้า $overdue วัน</span>";
                            } elseif ($days_left == 0) {
                                $days_info = "<span style='color:#fd7e14; font-weight:bold;'>🔔 วันนี้</span>";
                            } else {
                                $days_info = "<span style='color:#28a745; font-weight:bold;'>เหลือ $days_left วัน</span>";
                            }
                        }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['Modelname']); ?></strong></td>

                            <td><?php echo htmlspecialchars($row['BiosVer']); ?></td>

                            <td><?php echo htmlspecialchars($row['Testimage']); ?></td>

                            <td><?php echo htmlspecialchars($row['ssid']); ?></td>

                            <td><small><?php echo date('d/m/Y H:i', strtotime($row['updated_at'])); ?></small></td>

                            <td><?php echo htmlspecialchars($row['updated_by']); ?></td>

                            <td><?php echo $days_info; ?></td>

                            <td style="white-space: nowrap;">
                                <a href="javascript:void(0);" class="btn btn-blue"
                                    style="padding: 6px 10px; font-size: 12px; display: inline-block; margin-bottom: 2px;"
                                    onclick="openTrackingModal(<?= $row['id'] ?>, '<?= addslashes($row['Modelname']) ?>', '<?= $row['rtm_date'] ?>', '<?= $row['rtp_date'] ?>', '<?= addslashes($row['remark'] ?? '') ?>')">
                                    ⏱️ Tracking
                                </a>

                                <a href="javascript:void(0);" class="btn-delete"
                                    style="padding: 6px 10px; font-size: 12px; display: inline-block;"
                                    onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo addslashes($row['Modelname']); ?>')">
                                    🗑️ ลบ
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <div style="margin-top: 20px; display: flex; justify-content: center; gap: 5px;">
                <?php if ($page > 1): ?>
                    <a href="index.php?p=<?php echo $page - 1; ?>&search=<?php echo $search; ?>" class="btn-page">ก่อนหน้า</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="index.php?p=<?php echo $i; ?>&search=<?php echo $search; ?>"
                        class="btn-page <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="index.php?p=<?php echo $page + 1; ?>&search=<?php echo $search; ?>" class="btn-page">ถัดไป</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Update current time widget
        function updateCurrentTime() {
            const now = new Date();
            
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            document.getElementById('currentTime').textContent = `${hours}:${minutes}:${seconds}`;
            
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const dateString = now.toLocaleDateString('th-TH', dateOptions);
            document.getElementById('currentDate').textContent = dateString;
            
            const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            document.getElementById('currentTimezone').textContent = `เขตเวลา: ${timeZone}`;
        }

        // Update every second
        setInterval(updateCurrentTime, 1000);
        updateCurrentTime(); // Initial call

        function openTrackingModal(id, name, rtm, rtp, remark) {
            let daysLeftText = "กรุณาระบุวัน RTP เเละ RTM";
            let statusColor = "#6f42c1";

            if (rtp && rtp !== '0000-00-00' && rtp !== 'null' && rtp !== '') {
                let rtpDate = new Date(rtp);
                rtpDate.setHours(0, 0, 0, 0);

                let today = new Date();
                today.setHours(0, 0, 0, 0);

                let diffTime = rtpDate - today;
                let diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                if (diffDays < 0) {
                    let overdueDays = Math.abs(diffDays);
                    daysLeftText = `⚠️ ล่าช้ามาแล้ว ${overdueDays} วัน`;
                    statusColor = "#dc3545";
                } else if (diffDays === 0) {
                    daysLeftText = "🔔 ครบกำหนดวันนี้";
                    statusColor = "#fd7e14";
                } else {
                    daysLeftText = `เหลืออีก ${diffDays} วัน`;
                    statusColor = "#28a745";
                }
            }

            let actionButtons = "";
            if (rtm && rtm !== 'null' && rtm !== '0000-00-00' && rtm !== '') {
                actionButtons = `
            <button type="submit" name="complete_tracking" class="btn" style="background:#28a745; color:white; flex:2; padding:12px; border:none; border-radius:5px; cursor:pointer; font-weight:bold;">✅ สำเร็จ</button>
            <button type="submit" name="clear_tracking" class="btn" style="background:#dc3545; color:white; flex:1; padding:12px; border:none; border-radius:5px; cursor:pointer; font-weight:bold;" onclick="return confirm('ล้างข้อมูล Tracking?')">🗑️ ลบ</button>`;
            } else {
                actionButtons = `
            <div style="flex:2; color:#666; font-size:13px; text-align:center; padding:10px; border:1px dashed #ccc; border-radius:5px; background:#f9f9f9;">⚠️ บันทึก RTP เเละ RTM ก่อนเพื่อปิดงาน</div>
            <button type="submit" name="clear_tracking" class="btn" style="background:#dc3545; color:white; flex:1; padding:12px; border:none; border-radius:5px; cursor:pointer; font-weight:bold;">🗑️ ลบ</button>`;
            }

            Swal.fire({
                title: `Tracking: ${name}`,
                html: `
            <form id="tracking-form" method="POST" style="text-align:left;">
                <input type="hidden" name="modal_m_id" value="${id}">
                <input type="hidden" name="model_name" value="${name}">
                <div style="margin-bottom:10px;">
                    <label>RTM (Order Date):</label>
                    <input type="date" name="modal_rtm" class="swal2-input" value="${rtm || ''}">
                </div>
                <div style="margin-bottom:10px;">
                    <label>RTP (Cut-in Date):</label>
                    <input type="date" name="modal_rtp" class="swal2-input" value="${rtp || ''}">
                </div>
                <div style="margin-bottom:15px;">
                    <label>Remark:</label>
                    <input type="text" name="modal_remark" class="swal2-input" placeholder="หมายเหตุ" value="${remark || ''}">
                </div>

                <div style="background:#f8f9fa; padding:15px; border-radius:8px; text-align:center; margin-bottom:15px; border-left: 5px solid ${statusColor};">
                    <strong style="font-size:16px;">สถานะ: </strong> 
                    <span style="color:${statusColor}; font-weight:bold; font-size:18px;">${daysLeftText}</span>
                </div>

                <div style="display:flex; gap:10px;">
                    ${actionButtons}
                </div>
                <input type="hidden" name="action_tracking" value="1">
            </form>
        `,
                showConfirmButton: true,
                confirmButtonText: '💾 บันทึก',
                showCancelButton: true,
                cancelButtonText: 'ปิด',
                preConfirm: () => {
                    document.getElementById('tracking-form').submit();
                }
            });
        }

        function confirmDelete(id, name) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: "โมเดล " + name + " จะถูกลบ (ประวัติยังคงอยู่)",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'ใช่, ลบเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'index.php?delete_id=' + id;
                }
            });
        }
    </script>

</body>

</html>