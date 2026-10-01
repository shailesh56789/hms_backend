<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

class DashboardController
{
    private $db;

    public function __construct(SheetsDB $db) { $this->db = $db; }

    /** GET /api/dashboard/admin */
    public function admin()
    {
        Auth::authorize(['doctor', 'staff']);

        $patients  = $this->db->all('patients');
        $staff     = $this->db->all('staff');
        $diagnoses = $this->db->all('diagnoses');
        $products  = $this->db->all('products');
        $visits    = $this->db->all('visits');

        $diagMap = [];
        foreach ($diagnoses as $d) $diagMap[$d['id']] = $d['diagnosis_name'] ?? '';

        // Counts
        $doctors    = array_filter($staff, fn($s) => $s['role'] === 'doctor');
        $staffOnly  = array_filter($staff, fn($s) => $s['role'] === 'staff');
        $today      = date('Y-m-d');
        $todayVisits = array_filter($visits, fn($v) => ($v['visit_date'] ?? '') === $today);

        $counts = [
            'total_patients'  => count($patients),
            'total_doctors'   => count($doctors),
            'total_staff'     => count($staffOnly),
            'total_diagnoses' => count($diagnoses),
            'today_visits'    => count($todayVisits),
            'total_products'  => count($products),
        ];

        // Yearly visits
        $yearMap = [];
        foreach ($visits as $v) {
            $year = substr($v['visit_date'] ?? '', 0, 4);
            if ($year) $yearMap[$year] = ($yearMap[$year] ?? 0) + 1;
        }
        ksort($yearMap);
        $yearlyVisits = array_map(fn($year, $total) => ['year' => $year, 'total' => $total], array_keys($yearMap), $yearMap);

        // Diagnosis breakdown by year and status
        $breakdownMap = [];
        foreach ($diagnoses as $d) {
            $year   = substr($d['created_at'] ?? '', 0, 4);
            $status = $d['status'] ?? 'active';
            if ($year) {
                $key = $year . '_' . $status;
                $breakdownMap[$key] = ($breakdownMap[$key] ?? 0) + 1;
            }
        }
        $diagnosisBreakdown = [];
        foreach ($breakdownMap as $key => $total) {
            [$year, $status] = explode('_', $key, 2);
            $diagnosisBreakdown[] = ['year' => $year, 'status' => $status, 'total' => $total];
        }

        // Monthly diagnosis trends (current year)
        $curYear   = date('Y');
        $trendMap  = [];
        foreach ($patients as $p) {
            if (substr($p['created_at'] ?? '', 0, 4) !== $curYear) continue;
            $month   = (int)substr($p['created_at'], 5, 2);
            $diagId  = $p['diagnosis_id'] ?? '';
            $diagName = $diagMap[$diagId] ?? '';
            if ($diagName) {
                $key = $month . '_' . $diagName;
                $trendMap[$key] = ($trendMap[$key] ?? 0) + 1;
            }
        }
        $monthlyDiagnosisTrends = [];
        foreach ($trendMap as $key => $total) {
            [$month, $diagName] = explode('_', $key, 2);
            $monthlyDiagnosisTrends[] = ['month' => (int)$month, 'diagnosis_name' => $diagName, 'total' => $total];
        }

        // Latest 10 patients
        $latestPatients = array_slice(
            array_map(function($p) use ($diagMap) {
                unset($p['password'], $p['_row']);
                $p['diagnosis_name'] = $diagMap[$p['diagnosis_id'] ?? ''] ?? '';
                $p['relatives']      = [];
                return $p;
            }, $patients),
            0, 10
        );

        Response::success("Admin dashboard data", [
            'counts'                    => $counts,
            'yearly_visits'             => $yearlyVisits,
            'diagnosis_breakdown'       => $diagnosisBreakdown,
            'monthly_diagnosis_trends'  => $monthlyDiagnosisTrends,
            'latest_patients'           => array_values($latestPatients),
        ]);
    }

    /** GET /api/dashboard/doctor */
    public function doctor()
    {
        $user     = Auth::authorize(['doctor']);
        $doctorId = $user['id'];
        $visits   = $this->db->all('visits');

        $myVisits = array_filter($visits, fn($v) => (string)($v['doctor_id'] ?? '') === (string)$doctorId);

        // Monthly visits (last 12 months)
        $since       = date('Y-m-d', strtotime('-12 months'));
        $monthMap    = [];
        foreach ($myVisits as $v) {
            if (($v['visit_date'] ?? '') >= $since) {
                $month = substr($v['visit_date'], 0, 7);
                $monthMap[$month] = ($monthMap[$month] ?? 0) + 1;
            }
        }
        ksort($monthMap);
        $monthlyVisits = array_map(fn($m, $t) => ['month' => $m, 'total' => $t], array_keys($monthMap), $monthMap);

        // Diagnosis breakdown
        $diagMap = [];
        foreach ($this->db->all('diagnoses') as $d) $diagMap[$d['id']] = $d['status'] ?? 'active';
        $statusCount = [];
        foreach ($myVisits as $v) {
            $status = $diagMap[$v['diagnosis_id'] ?? ''] ?? 'active';
            $statusCount[$status] = ($statusCount[$status] ?? 0) + 1;
        }
        $diagnosisBreakdown = array_map(fn($s, $t) => ['status' => $s, 'total' => $t], array_keys($statusCount), $statusCount);

        // Latest patients
        $patientIds = array_unique(array_column(iterator_to_array((function() use ($myVisits) { yield from $myVisits; })()), 'patient_id'));
        $patients   = $this->db->all('patients');
        $latestPatients = [];
        foreach ($patientIds as $pid) {
            foreach ($patients as $p) {
                if ((string)$p['id'] === (string)$pid) {
                    unset($p['password'], $p['_row']);
                    $latestPatients[] = $p;
                    break;
                }
            }
        }
        $latestPatients = array_slice($latestPatients, 0, 3);

        $totalPatients  = count(array_unique(array_column(array_values($myVisits), 'patient_id')));
        $totalDiagnoses = count(array_filter($myVisits, fn($v) => !empty($v['diagnosis_id'])));

        Response::success("Doctor dashboard data", [
            'counts'               => ['total_patients' => $totalPatients, 'total_diagnoses' => $totalDiagnoses],
            'monthly_visits'       => $monthlyVisits,
            'diagnosis_breakdown'  => $diagnosisBreakdown,
            'latest_patients'      => array_values($latestPatients),
        ]);
    }

    /** GET /api/dashboard/patient */
    public function patient()
    {
        $user      = Auth::authorize(['user']);
        $patientId = $user['id'];
        $visits    = $this->db->all('visits');
        $myVisits  = array_filter($visits, fn($v) => (string)($v['patient_id'] ?? '') === (string)$patientId);

        $since     = date('Y-m-d', strtotime('-12 months'));
        $monthMap  = [];
        foreach ($myVisits as $v) {
            if (($v['visit_date'] ?? '') >= $since) {
                $month = substr($v['visit_date'], 0, 7);
                $monthMap[$month] = ($monthMap[$month] ?? 0) + 1;
            }
        }
        ksort($monthMap);
        $monthlyVisits = array_map(fn($m, $t) => ['month' => $m, 'total' => $t], array_keys($monthMap), $monthMap);

        $diagMap = [];
        foreach ($this->db->all('diagnoses') as $d) {
            $diagMap[$d['id']] = ['name' => $d['diagnosis_name'] ?? '', 'status' => $d['status'] ?? 'active'];
        }
        $prodMap = [];
        foreach ($this->db->all('products') as $pr) $prodMap[$pr['id']] = $pr['product_name'] ?? '';
        $staffMap = [];
        foreach ($this->db->all('staff') as $s) $staffMap[$s['id']] = $s['name'];

        $recentDiagnoses = [];
        $visitsArr = array_values($myVisits);
        usort($visitsArr, fn($a, $b) => strcmp($b['visit_date'] ?? '', $a['visit_date'] ?? ''));
        foreach (array_slice($visitsArr, 0, 5) as $v) {
            if (!empty($v['diagnosis_id']) && isset($diagMap[$v['diagnosis_id']])) {
                $recentDiagnoses[] = [
                    'diagnosis_name' => $diagMap[$v['diagnosis_id']]['name'],
                    'status'         => $diagMap[$v['diagnosis_id']]['status'],
                    'diagnosis_date' => $v['visit_date'],
                    'doctor_name'    => $staffMap[$v['doctor_id'] ?? ''] ?? '',
                    'product_name'   => $prodMap[$v['product_id'] ?? ''] ?? '',
                ];
            }
        }

        $totalDiagnoses = count(array_filter($myVisits, fn($v) => !empty($v['diagnosis_id'])));

        Response::success("Patient dashboard data", [
            'counts'           => ['total_diagnoses' => $totalDiagnoses],
            'monthly_visits'   => $monthlyVisits,
            'recent_diagnoses' => $recentDiagnoses,
        ]);
    }
}
