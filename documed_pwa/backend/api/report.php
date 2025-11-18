<?php

// Robust path resolution (works under web server and CLI runner)
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'daily') {
	$date = $_GET['date'] ?? date('Y-m-d');
	$stmt = $pdo->prepare("SELECT * FROM transactions WHERE date = ?");
	$stmt->execute([$date]);
	$data = $stmt->fetchAll();
	echo json_encode(['report' => $data]);
	exit;
}

if ($action === 'weekly') {
	$start = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
	$end = $_GET['end'] ?? date('Y-m-d', strtotime('sunday this week'));
	$stmt = $pdo->prepare("SELECT * FROM transactions WHERE date BETWEEN ? AND ?");
	$stmt->execute([$start, $end]);
	$data = $stmt->fetchAll();
	echo json_encode(['report' => $data]);
	exit;
}

if ($action === 'monthly') {
	$month = $_GET['month'] ?? date('m');
	$year = $_GET['year'] ?? date('Y');
	$stmt = $pdo->prepare("SELECT * FROM transactions WHERE MONTH(date) = ? AND YEAR(date) = ?");
	$stmt->execute([$month, $year]);
	$data = $stmt->fetchAll();
	echo json_encode(['report' => $data]);
	exit;
}

// Dashboard stat: reports count (prefers transactions; falls back to checkups)
if ($action === 'count') {
	$scope = $_GET['scope'] ?? $_POST['scope'] ?? 'all'; // all|today|week|month|year

	// Build date conditions for transactions.date and checkups.created_at
	$txWhere = '';
	$txParams = [];
	$ckWhere = '';
	$ckParams = [];
	$today = date('Y-m-d');
	if ($scope === 'today') {
		$txWhere = ' WHERE date = ?';
		$txParams = [$today];
		$ckWhere = ' WHERE DATE(created_at) = ?';
		$ckParams = [$today];
	} elseif ($scope === 'week') {
		$start = date('Y-m-d', strtotime('monday this week'));
		$end = date('Y-m-d', strtotime('sunday this week'));
		$txWhere = ' WHERE date BETWEEN ? AND ?';
		$txParams = [$start, $end];
		$ckWhere = ' WHERE DATE(created_at) BETWEEN ? AND ?';
		$ckParams = [$start, $end];
	} elseif ($scope === 'month') {
		$m = date('m'); $y = date('Y');
		$txWhere = ' WHERE MONTH(date) = ? AND YEAR(date) = ?';
		$txParams = [$m, $y];
		$ckWhere = ' WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?';
		$ckParams = [$m, $y];
	} elseif ($scope === 'year') {
		$y = date('Y');
		$txWhere = ' WHERE YEAR(date) = ?';
		$txParams = [$y];
		$ckWhere = ' WHERE YEAR(created_at) = ?';
		$ckParams = [$y];
	}

	// Try transactions first (table may be empty or missing)
	$count = 0; $txTried = false;
	try {
		$sql = 'SELECT COUNT(*) FROM transactions' . $txWhere;
		$st = $pdo->prepare($sql);
		$st->execute($txParams);
		$c = (int)$st->fetchColumn();
		$txTried = true;
		if ($c > 0) { echo json_encode(['count' => $c]); exit; }
	} catch (Throwable $e) { /* ignore and fallback */ }

	// Fallback: count checkups
	try {
		$sql2 = 'SELECT COUNT(*) FROM checkups' . $ckWhere;
		$st2 = $pdo->prepare($sql2);
		$st2->execute($ckParams);
		$count = (int)$st2->fetchColumn();
	} catch (Throwable $e2) {
		// Last resort: zero if table missing
		$count = 0;
	}
	echo json_encode(['count' => $count, 'source' => ($txTried ? 'fallback_checkups' : 'checkups')]);
	exit;
}

if ($action === 'illness_stats') {
	// Unified role normalization (same as clinic_overview)
	$roleInput = $_GET['role'] ?? $_POST['role'] ?? 'Student';
	$rawRoleIn = strtoupper(trim($roleInput));
	$roleMap = [
		'STUDENT'=>'Student','FACULTY'=>'Faculty','STAFF'=>'Staff','TEACHER'=>'Faculty',
		'NON-TEACHING'=>'Staff','NONTEACHING'=>'Staff','NON TEACHING'=>'Staff','ADMIN'=>'Staff','EMPLOYEE'=>'Staff'
	];
	$normRole = function($v) use($roleMap){
		$V = strtoupper(trim($v ?? ''));
		if ($V==='ALL') return 'All';
		$V = str_replace(['-'],' ', $V);
		$V = preg_replace('/\s+/',' ', $V);
		$mapKey = $V;
		if (!isset($roleMap[$mapKey]) && isset($roleMap[str_replace(' ','-',$mapKey)])) {
			$mapKey = str_replace(' ','-',$mapKey);
		}
		$r = $roleMap[$mapKey] ?? null;
		if ($r===null) {
			// heuristic student if year tokens found
			if (preg_match('/\b(i|ii|iii|iv|1st|2nd|3rd|4th|freshman|sophomore|junior|senior)\b/i',$V)) return 'Student';
			return 'Staff';
		}
		return $r;
	};
	$role = $normRole($rawRoleIn);
	$hasRole = true;

	// Optional date filters
	$dateMode = $_GET['dateMode'] ?? $_POST['dateMode'] ?? 'all'; // all|daily|weekly|monthly
	$params = [];
	$whereDate = '';
	if ($dateMode === 'daily') {
		$date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
		$whereDate = " AND DATE(c.created_at) = ?";
		$params[] = $date;
	} elseif ($dateMode === 'weekly') {
		$start = $_GET['start'] ?? $_POST['start'] ?? date('Y-m-d', strtotime('monday this week'));
		$end = $_GET['end'] ?? $_POST['end'] ?? date('Y-m-d', strtotime('sunday this week'));
		$whereDate = " AND DATE(c.created_at) BETWEEN ? AND ?";
		$params[] = $start; $params[] = $end;
	} elseif ($dateMode === 'monthly') {
		$month = $_GET['month'] ?? $_POST['month'] ?? date('m');
		$year = $_GET['year'] ?? $_POST['year'] ?? date('Y');
		$whereDate = " AND MONTH(c.created_at) = ? AND YEAR(c.created_at) = ?";
		$params[] = $month; $params[] = $year;
	}

	// SUPER SIMPLIFIED: pull recent checkups and classify using client_type only.
	// Build proper date filtering based on dateMode
	$dateWhere = '';
	$dateParams = [];
	if ($dateMode === 'daily') {
		$date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
		$dateWhere = 'WHERE DATE(created_at) = ?';
		$dateParams[] = $date;
	} elseif ($dateMode === 'weekly') {
		$start = $_GET['start'] ?? $_POST['start'] ?? date('Y-m-d', strtotime('monday this week'));
		$end = $_GET['end'] ?? $_POST['end'] ?? date('Y-m-d', strtotime('sunday this week'));
		$dateWhere = 'WHERE DATE(created_at) BETWEEN ? AND ?';
		$dateParams[] = $start; $dateParams[] = $end;
	} elseif ($dateMode === 'monthly') {
		$month = $_GET['month'] ?? $_POST['month'] ?? date('m');
		$year = $_GET['year'] ?? $_POST['year'] ?? date('Y');
		$dateWhere = 'WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?';
		$dateParams[] = $month; $dateParams[] = $year;
	}
	$limit = 500;
	try {
		$sql = "SELECT id, assessment, present_illness, client_type, year_and_course, department, created_at FROM checkups $dateWhere ORDER BY created_at DESC LIMIT $limit";
		if ($dateParams) {
			$stmt = $pdo->prepare($sql);
			$stmt->execute($dateParams);
		} else {
			$stmt = $pdo->query($sql);
		}
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	} catch (Throwable $eQ) {
		echo json_encode(['success'=>false,'message'=>'illness_stats simplified query failed','error'=>$eQ->getMessage(),'sql'=>$sql,'dateParams'=>$dateParams]);
		exit;
	}
	// Filter by audience if not All (map Non-Teaching explicitly to Staff)
	$unmatchedRoles = [];
	if ($role !== 'All') {
		$rows = array_values(array_filter($rows, function($r) use($role,$normRole,&$unmatchedRoles){
			$ctRaw = trim($r['client_type'] ?? '');
			$mapped = $normRole($ctRaw === '' ? 'Student' : $ctRaw);
			if (!in_array($mapped,['Student','Faculty','Staff'])) { $unmatchedRoles[] = $ctRaw; $mapped = 'Staff'; }
			return $mapped === $role;
		}));
	}
	// Additional filters: department, year, course (student-specific parsing)
	$departmentParam = trim($_GET['department'] ?? $_POST['department'] ?? '');
	$yearParam = trim($_GET['year'] ?? $_POST['year'] ?? '');
	$courseParam = trim($_GET['course'] ?? $_POST['course'] ?? '');
	$origFilters = ['department'=>$departmentParam,'year'=>$yearParam,'course'=>$courseParam,'audience'=>$role,'dateMode'=>$dateMode];
	$afterRoleFilterCount = count($rows);
	$stageDeptCount = $afterRoleFilterCount; $stageYearCount = null; $stageCourseCount = null;
	if ($role === 'Student') {
		$romanMap = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6];
		$extractYear = function($s) use($romanMap){
			$S = strtoupper($s ?? '');
			if (preg_match('/\b(IV|V|VI|III|II|I)\b/', $S, $m)) return $romanMap[$m[1]] ?? null;
			if (preg_match('/\b([1-6])(?:ST|ND|RD|TH)?\b/', $S, $m)) return intval($m[1]);
			if (preg_match('/([1-6])(ST|ND|RD|TH)?(YR|YEAR)/', $S, $m)) return intval($m[1]);
			return null;
		};
		$pack = function($s){ return preg_replace('/[^A-Z0-9]/','', strtoupper($s ?? '')); };
		$tokensForCourse = function($courseName){
			$name = strtoupper(trim($courseName ?? ''));
			if ($name==='') return [];
			$words = preg_split('/[^A-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
			$stop = ['OF','IN','AND','MAJOR','WITH','&'];
			$ac = '';
			foreach ($words as $w) { if (in_array($w,$stop)) continue; $ac .= $w[0]; }
			$tokens = $ac ? [$ac] : [];
			$map = [
				'INFORMATION TECHNOLOGY'=>['BSIT','IT'],
				'COMPUTER SCIENCE'=>['BSCS','CS'],
				'PUBLIC ADMINISTRATION'=>['BPA'],
				'HOSPITALITY MANAGEMENT'=>['BSHM','HM','BHM'],
				'BIOLOGY'=>['BSBIO','BIO'],
				'NUTRITION'=>['BSND','ND'],
				'SOCIAL WORK'=>['BSSW','SW'],
				'ENGLISH'=>['BAEL','EL','ENG'],
				'ECONOMICS'=>['BAECO','ECO','ECON'],
				'MATHEMATICS'=>['BSMATH','MATH'],
			];
			foreach ($map as $k=>$arr) { if (str_contains($name,$k)) { $tokens = array_values(array_unique(array_merge($tokens,$arr))); } }
			return $tokens;
		};
		$yearWanted = $yearParam!=='' ? intval($yearParam) : null;
		$courseTokens = $courseParam!=='' ? $tokensForCourse($courseParam) : [];
		$deptWanted = strtoupper($departmentParam);
		$deptSyns = [
			'CCS'=>['COMPUTING SCIENCES','COMPUTER SCIENCE','INFORMATION TECHNOLOGY','COMPUTER STUDIES'],
			'CASL'=>['ARTS','SCIENCES','LETTERS'],
			'CHTM'=>['HOSPITALITY','TOURISM','HM'],
			'CBPA'=>['BUSINESS','PUBLIC ADMINISTRATION','BPA'],
			'CIT'=>['INDUSTRIAL TECHNOLOGY','TECHNOLOGY'],
			'CTE'=>['EDUCATION','TEACHER','BTLED','BTVTED','BSED'],
		];
		$inferredDeptHits = [];
		$deptMapCodes = [
			'CCS'=>['BSIT','IT','BSCS','CS','BSMATH','MATH','COMSCI','COMPUTER','INFORMATIONTECH'],
			'CASL'=>['BSBIO','BIO','BSND','ND','BSSW','SW','BAEL','BAENG','EL','BAECO','ECON','ECO','MATH'],
			'CBPA'=>['BPA','BPUBLICADMIN','PUBLICADMIN','BUSINESSADMIN','BSBA'],
			'CHTM'=>['BSHM','HM','BHM','HOSPITALITY','TOURISM'],
			'CIT'=>['BIT','INDTECH','INDUSTRIALTECH','TECH'],
			'CTE'=>['BTLED','BTTLED','BTVTED','BTVTE','BSED','BSE','EDUCATION','TEACHER']
		];
		$rows = array_values(array_filter($rows, function($r) use($deptWanted,$yearWanted,$courseTokens,$extractYear,$pack,$deptSyns,$deptMapCodes,&$inferredDeptHits){
			$yc = strtoupper(($r['year_and_course'] ?? ''));
			if ($deptWanted && $deptWanted!=='ALL') {
				$dept = strtoupper(($r['department'] ?? ''));
				$deptMatch = false;
				if ($dept !== '' && (str_contains($dept,$deptWanted))) { $deptMatch = true; }
				if (!$deptMatch && $dept !== '') {
					foreach (($deptSyns[$deptWanted] ?? []) as $sy){ if ($sy && str_contains($dept,$sy)) { $deptMatch=true; break; } }
				}
				if (!$deptMatch) {
					// Infer from course codes
					$packed = $pack($yc); // remove delimiters
					foreach (($deptMapCodes[$deptWanted] ?? []) as $code){
						$codePacked = preg_replace('/[^A-Z0-9]/','', strtoupper($code));
						if ($codePacked && str_contains($packed,$codePacked)) { $deptMatch=true; $inferredDeptHits[] = ['rowId'=>$r['id'] ?? null,'deptWanted'=>$deptWanted,'matchedCode'=>$code,'yearCourse'=>$yc]; break; }
					}
				}
				if (!$deptMatch) return false;
			}
			if ($yearWanted) {
				$found = $extractYear($yc);
				if ($found!==null && $found!==$yearWanted) return false;
			}
			if ($courseTokens) {
				$packed = $pack($yc);
				$ok=false; foreach ($courseTokens as $t){ $tk = preg_replace('/[^A-Z0-9]/','', strtoupper($t)); if ($tk && str_contains($packed,$tk)) { $ok=true; break; } }
				if (!$ok) return false;
			}
			return true;
		}));
		$stageDeptCount = $rows ? count($rows) : 0;
		if ($yearWanted) $stageYearCount = $stageDeptCount;
		if ($courseTokens) $stageCourseCount = $stageDeptCount;
		$inferredDeptHitsCount = count($inferredDeptHits);
	} else {
		$inferredDeptHits = [];
		$inferredDeptHitsCount = 0;
		// Faculty/Staff department filter
		if ($departmentParam!=='' && strtoupper($departmentParam)!=='ALL') {
			$want = strtoupper($departmentParam);
			$rows = array_values(array_filter($rows, function($r) use($want){
				$dept = strtoupper($r['department'] ?? '');
				if ($dept==='') return false;
				if (str_contains($dept,$want)) return true;
				// synonyms quick pass
				$syns = [
					'CCS'=>['COMPUTING','INFORMATION TECHNOLOGY','COMPUTER'],
					'CHTM'=>['HOSPITALITY','TOURISM','HM'],
					'CBPA'=>['BUSINESS','PUBLIC'],
					'CASL'=>['ARTS','SCIENCES','LETTERS'],
					'CIT'=>['INDUSTRIAL','TECHNOLOGY'],
					'CTE'=>['EDUCATION','TEACHER','BTLED','BTVTED','BSED']
				][$want] ?? [];
				foreach ($syns as $sy){ if ($sy && str_contains($dept,$sy)) return true; }
				return false;
			}));
		}
	}
	// Basic illness keyword buckets (minimal set for display assurance)
	$buckets = [
		'Headache'=>0,'Cough'=>0,'Cold'=>0,'Back Pain'=>0,'Hypertension'=>0,'Fever'=>0,'Others'=>0
	];
	foreach ($rows as $r) {
		$txt = strtolower(trim(($r['assessment'] ?? '') . ' ' . ($r['present_illness'] ?? '')));
		if ($txt === '') { $buckets['Others']++; continue; }
		$matched = false;
		$patterns = [
			[['headache','migraine'],'Headache'],
			[['cough'],'Cough'],
			[['cold','colds','runny nose'],'Cold'],
			[['back pain','backpain'],'Back Pain'],
			[['hypertension','high blood'],'Hypertension'],
			[['fever','febrile'],'Fever']
		];
		foreach ($patterns as $pair) {
			$keys = $pair[0]; $label = $pair[1];
			foreach ($keys as $k) { if ($k && str_contains($txt,$k)) { $buckets[$label]++; $matched=true; break; } }
			if ($matched) break;
		}
		if (!$matched) $buckets['Others']++;
	}
	$labels = array_keys($buckets);
	$values = array_values($buckets);
	$total = array_sum($values);

	// Compute role counts based on client_type fallback and heuristics from department/year_and_course
	$roleCounts = ['Student'=>0,'Faculty'=>0,'Staff'=>0];
	$genderCounts = ['Male'=>0,'Female'=>0,'Other'=>0];
	foreach ($rows as $r) {
		$mapped = $normRole($r['client_type'] ?? '');
		if ($mapped === 'All') $mapped = 'Student'; // shouldn't occur here
		if (!isset($roleCounts[$mapped])) $mapped = 'Staff';
		$roleCounts[$mapped]++;
		$g = strtolower(trim($r['gender'] ?? ''));
		if ($g === 'male' || $g === 'm') $genderCounts['Male']++; elseif ($g === 'female' || $g === 'f') $genderCounts['Female']++; elseif ($g !== '') $genderCounts['Other']++;
	}

		echo json_encode([
			'success'=>true,
			'labels'=>$labels,
			'values'=>$values,
			'total'=>$total,
			'roleCounts'=>$roleCounts,
			'genderCounts'=>$genderCounts,
			'debug'=>array_merge([
				'filters'=>$origFilters,
				'afterRoleFilterCount'=>$afterRoleFilterCount,
				'afterFilterCount'=>count($rows),
				'stageDeptCount'=>$stageDeptCount,
				'stageYearCount'=>$stageYearCount,
				'stageCourseCount'=>$stageCourseCount,
				'unmatchedRoles'=>$unmatchedRoles
			], [
				'inferredDeptHits'=>($inferredDeptHits ?? []),
				'inferredDeptHitsCount'=>($inferredDeptHitsCount ?? 0)
			])
		]);
	exit;

	// For Students: apply robust PHP-level filters for year/course due to free-form inputs (e.g., 'IV-BSIT', '4thyear BPA').
	$yearReq = trim($year);
	$courseReq = trim($course);
	if ($role === 'Student') {
		$pack = function($s){ return preg_replace('/[^A-Z0-9]/','', strtoupper($s ?? '')); };
		$romanMap = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6];
		$extractYear = function($s) use($romanMap,$pack){
			$S = strtoupper($s ?? '');
			if (preg_match('/\\b(IV|V|VI|III|II|I)\\b/', $S, $m)) { return $romanMap[$m[1]] ?? null; }
			if (preg_match('/\\b([1-6])(?:ST|ND|RD|TH)?\\b/', $S, $m)) { return intval($m[1]); }
			$P = $pack($S);
			if (preg_match('/([1-6])(ST|ND|RD|TH)?(YR|YEAR)/', $P, $m)) { return intval($m[1]); }
			return null;
		};
		$tokensForCourse = function($courseName){
			$name = strtoupper(trim($courseName ?? ''));
			if ($name === '') return [];
			$tokens = [];
			// Acronym from words (skip stopwords)
			$words = preg_split('/[^A-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
			$stop = ['OF','IN','AND','MAJOR','WITH','&'];
			$ac = '';
			foreach ($words as $w) { if (in_array($w,$stop)) continue; $ac .= $w[0]; }
			if ($ac) $tokens[] = $ac; // e.g., BSIT
			// Known mappings to match common codes in records
			$map = [
				'INFORMATION TECHNOLOGY'=>['BSIT','IT'],
				'COMPUTER SCIENCE'=>['BSCS','CS'],
				'PUBLIC ADMINISTRATION'=>['BPA'],
				'TECHNOLOGY AND LIVELIHOOD EDUCATION'=>['BTLED','BTTLED'],
				'SECONDARY EDUCATION'=>['BSED','BSE'],
				'TECHNICAL VOCATIONAL TEACHER EDUCATION'=>['BTVTED','BTVTE'],
				'INDUSTRIAL TECHNOLOGY'=>['BIT','INDTECH'],
				'HOSPITALITY MANAGEMENT'=>['BSHM','BHM','HM'],
				'BIOLOGY'=>['BSBIO','BIO'],
				'NUTRITION AND DIETETICS'=>['BSND','ND'],
				'SOCIAL WORK'=>['BSSW','SW'],
				'ENGLISH LANGUAGE'=>['BAEL','BAENG','EL'],
				'ECONOMICS'=>['BAECO','ECON','ECO'],
				'MATHEMATICS'=>['BSMATH','MATH']
			];
			foreach ($map as $k=>$arr) { if (str_contains($name, $k)) { $tokens = array_values(array_unique(array_merge($tokens,$arr))); } }
			return $tokens;
		};
		$deptMatches = function($filterCode, $rowDept, $yc) {
			if (!$filterCode || strtoupper($filterCode)==='ALL') return true;
			$F = strtoupper($filterCode);
			$D = strtoupper(trim($rowDept ?? ''));
			if ($D !== '') {
				// contains match and common keywords
				$kw = [
					'CASL'=>['CASL','ARTS, SCIENCES AND LETTERS','ARTS AND SCIENCES','CAS'],
					'CBPA'=>['CBPA','BUSINESS AND PUBLIC ADMINISTRATION','PUBLIC ADMINISTRATION','BUSINESS ADMINISTRATION'],
					'CCS' =>['CCS','COMPUTING SCIENCES','COMPUTER STUDIES','COMPUTER SCIENCE','INFORMATION TECHNOLOGY'],
					'CHTM'=>['CHTM','HOSPITALITY AND TOURISM MANAGEMENT','HOSPITALITY MANAGEMENT','TOURISM'],
					'CTE' =>['CTE','TEACHER EDUCATION','EDUCATION'],
					'CIT' =>['CIT','INDUSTRIAL TECHNOLOGY','TECHNOLOGY']
				][$F] ?? [$F];
				foreach ($kw as $k) { if ($k && str_contains($D, $k)) return true; }
			}
			$P = preg_replace('/[^A-Z0-9]/','', strtoupper($yc ?? ''));
			$map = [
				'CBPA' => ['BPA'],
				'CCS'  => ['BSIT','IT','BSCS','CS','MATH','BSMATH'],
				'CHTM' => ['BSHM','BHM','HM'],
				'CTE'  => ['BTLED','BTTLED','BTVTED','BTVTE','BSED','BSE'],
				'CIT'  => ['BIT','INDTECH'],
				'CASL' => ['BSBIO','BIO','BSND','ND','BSSW','SW','BAEL','BAENG','EL','BAECO','ECON','ECO']
			];
			foreach (($map[$F] ?? []) as $code) { if ($code && str_contains($P,$code)) return true; }
			return false;
		};
		$wantYear = $yearReq !== '' ? intval($yearReq) : null;
		$wantCourseTokens = $courseReq !== '' ? $tokensForCourse($courseReq) : [];
		$rows = array_values(array_filter($rows, function($r) use($extractYear,$wantYear,$wantCourseTokens,$pack,$department,$deptMatches){
			$yc = (($r['c_year_course'] ?? '') . ' ' . ($r['u_year_course'] ?? ''));
			// Department filter (contains + inference)
			$deptOk = true;
			if ($department && strtoupper($department) !== 'ALL') {
				$rowDept = $r['dept_eff'] ?? '';
				$deptOk = $deptMatches($department, $rowDept, $yc);
				if (!$deptOk) return false;
			}
			if ($wantYear) {
				$found = $extractYear($yc);
				// Only exclude if a conflicting year is explicitly found; if not detectable, keep the row
				if ($found !== null && $found !== $wantYear) return false;
			}
			if ($wantCourseTokens) {
				$P = $pack($yc);
				$ok = false; foreach ($wantCourseTokens as $t) { $tP = preg_replace('/[^A-Z0-9]/','', strtoupper($t)); if ($tP && str_contains($P, $tP)) { $ok = true; break; } }
				if (!$ok) return false;
			}
			return true;
		}));
	}

	// Role counts across selected date range (not tied to single role)
	$roleCounts = ['Student'=>0,'Faculty'=>0,'Staff'=>0];
	// Recompute counts using same role_effective logic to avoid undercounting
	$cntSql = "SELECT $roleExpr AS role_eff, COUNT(*) AS c " . $joinSql;
	$cntWhere = '';
	$cntBinds = [];
	if ($whereDate) { $cntWhere .= $whereDate; $cntBinds = array_merge($cntBinds, $params); }
	$cntSql .= $cntWhere . " GROUP BY role_eff";
	$rstmt = $pdo->prepare($cntSql);
	$rstmt->execute($cntBinds);
	while ($r = $rstmt->fetch(PDO::FETCH_ASSOC)) {
		$rr = $roleMap[$r['role_eff']] ?? $r['role_eff'];
		if (isset($roleCounts[$rr])) $roleCounts[$rr] = intval($r['c']);
	}

	$buckets = [
		'Colds'=>0, 'Headache'=>0, 'Vertigo'=>0, 'Fainting'=>0, 'Allergy'=>0,
		'Hypertension'=>0, 'Cough'=>0, 'Diabetes'=>0, 'Back Pain'=>0, 'Sorethroat/ wound'=>0,
		'Others'=>0
	];
	function put_inc(&$b, $k){ $b[$k] = ($b[$k] ?? 0) + 1; }
	foreach ($rows as $r) {
		$text = strtolower(trim(($r['assessment'] ?? '') . ' ' . ($r['present_illness'] ?? '')));
		if ($text === '') { put_inc($buckets,'Others'); continue; }
		if (str_contains($text,'cold')) put_inc($buckets,'Colds');
		elseif (str_contains($text,'headache')) put_inc($buckets,'Headache');
		elseif (str_contains($text,'vertigo')) put_inc($buckets,'Vertigo');
		elseif (str_contains($text,'faint')) put_inc($buckets,'Fainting');
		elseif (str_contains($text,'hyperacidity') || str_contains($text,'allergy')) put_inc($buckets,'Allergy');
		elseif (str_contains($text,'hypertension') || str_contains($text,'high blood')) put_inc($buckets,'Hypertension');
		elseif (str_contains($text,'cough')) put_inc($buckets,'Cough');
		elseif (str_contains($text,'diabetes')) put_inc($buckets,'Diabetes');
		elseif (str_contains($text,'back pain') || str_contains($text,'backpain')) put_inc($buckets,'Back Pain');
		elseif (str_contains($text,'sore throat') || str_contains($text,'sorethroat') || str_contains($text,'wound')) put_inc($buckets,'Sorethroat/ wound');
		else put_inc($buckets,'Others');
	}

	// Build the pie according to the selected audience
	$audienceOrder = [
		'Student' => ['Colds','Headache','Vertigo','Fainting','Allergy','Others'],
		'Faculty' => ['Colds','Hypertension','Headache','Diabetes','Allergy','Others'],
		'Staff'   => ['Hypertension','Cough','Allergy','Back Pain','Sorethroat/ wound','Others']
	];
	if ($role === 'All') {
		$labels = array_keys($buckets);
	} else {
		// Start with curated order, then append any categories that actually have counts for this role
		$curated = $audienceOrder[$role] ?? [];
		$nonZero = [];
		foreach ($buckets as $k => $v) { if (intval($v) > 0) $nonZero[] = $k; }
		$labels = array_values(array_unique(array_merge($curated, $nonZero)));
		// Fallback: if still empty, show all buckets
		if (!$labels) { $labels = array_keys($buckets); }
	}
	$values = array_map(fn($k)=> intval($buckets[$k] ?? 0), $labels);
	$total = 0; foreach ($values as $v) { $total += (int)$v; }
	echo json_encode(['success'=>true,'labels'=>$labels,'values'=>$values,'total'=>$total,'hasRole'=>$hasRole,'roleCounts'=>$roleCounts]);
	exit;
}

// Comprehensive clinic overview (tabular) built from checkups
if ($action === 'clinic_overview') {
	$start = $_GET['start'] ?? $_POST['start'] ?? '';
	$end = $_GET['end'] ?? $_POST['end'] ?? '';
	if (!$start || !$end) {
		// default to current month if not provided
		$start = date('Y-m-01');
		$end = date('Y-m-t');
	}

	// Determine if legacy role column exists; prefer client_type
	$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
	$hasLegacyRole = false;
	if ($dbName) {
		try {
			$cstmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'checkups' AND COLUMN_NAME = 'role'");
			$cstmt->execute([$dbName]);
			$hasLegacyRole = intval($cstmt->fetchColumn()) > 0;
		} catch (Throwable $e) { $hasLegacyRole = false; }
	}

	$roleMap = [
		'STUDENT'=>'Student',
		'FACULTY'=>'Faculty',
		'STAFF'=>'Staff',
		'TEACHER'=>'Faculty',
		'NON-TEACHING'=>'Staff',
		'NONTEACHING'=>'Staff',
		'NON TEACHING'=>'Staff',
		'ADMIN'=>'Staff', // treat generic admin as Staff unless clarified
		'EMPLOYEE'=>'Staff'
	];
	$roles = ['Student','Faculty','Staff'];

	// Ensure $hasRole flag used below is defined (was causing warnings)
	$hasRole = $hasLegacyRole;

	// Pull checkups in date range
	$whereDate = "WHERE DATE(created_at) BETWEEN ? AND ?";
	$sql = $hasLegacyRole
		? "SELECT COALESCE(NULLIF(client_type,''), role) AS role, assessment, present_illness, remarks FROM checkups $whereDate"
		: "SELECT client_type AS role, assessment, present_illness, remarks FROM checkups $whereDate";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([$start, $end]);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// Token helpers
	$norm = function($s){ return strtolower(trim($s ?? '')); };
	$incr = function(&$arr,$key,$role,$val=1) use($roles){ if(!isset($arr[$key])) $arr[$key] = array_fill_keys($roles,0); $arr[$key][$role] += (int)$val; };
	$getRole = function($raw) use($roleMap){
		$val = strtoupper(trim($raw ?? ''));
		if ($val==='') return 'Student';
		// unify separators
		$val = str_replace(['  '],' ', $val);
		$val = str_replace(['-'], ' ', $val);
		$val = preg_replace('/\s+/', ' ', $val);
		$valTrim = str_replace(' ', '-', $val); // allow both space and hyphen forms
		$mapKey = $val;
		if (!isset($roleMap[$mapKey]) && isset($roleMap[str_replace(' ','-',$mapKey)])) {
			$mapKey = str_replace(' ','-',$mapKey);
		}
		$r = $roleMap[$mapKey] ?? ($roleMap[$valTrim] ?? null);
		if ($r===null) {
			// heuristic: contains year tokens => student
			if (preg_match('/\b(i|ii|iii|iv|1st|2nd|3rd|4th|freshman|sophomore|junior|senior)\b/i',$val)) return 'Student';
			return 'Staff'; // default fallback
		}
		return $r;
	};
	$debugRawRoles = [];

	// Illness categorization (broad list matching your sheet)
	$illnessMap = [
		'Abdominal Pain'=>['abdominal pain','abd pain'],
		'Allergies'=>['allergy','allergies','hyperacidity'],
		'Allergic Rhinitis'=>['allergic rhinitis'],
		'Asthma'=>['asthma'],
		'Anemia'=>['anemia'],
		'Back Pain/Nape Pain'=>['back pain','backpain','nape pain'],
		'Body Pain'=>['body pain','body aches','myalgia'],
		'Bronchitis'=>['bronchitis'],
		'Burn'=>['burn'],
		'Chest Pain(Angina/Cardiac Related)'=>['chest pain','angina','cardiac'],
		'Clogged nose/Colds/Rhinovirus infection'=>['cold','colds','rhinovirus','clogged nose','common cold','runny nose'],
		'Conjunctivitis/Sore eyes'=>['conjunctivitis','sore eyes','sore eye'],
		'Constipation'=>['constipation'],
		'Cough'=>['cough'],
		'Dengue'=>['dengue'],
		'Difficulty of breathing'=>['shortness of breath','difficulty breathing','dyspnea'],
		'Diabetes'=>['diabetes'],
		'Dizziness'=>['dizzy','dizziness','vertigo'],
		'Dysmenorrhea'=>['dysmenorrhea'],
		'Epilepsy'=>['epilepsy','seizure'],
		'Eye Irritation'=>['eye irritation','itchy eye'],
		'Fever'=>['fever','febrile'],
		'Fractures'=>['fracture'],
		'Fungal Infection'=>['fungal','tinea'],
		'Gastritis/Acid Peptic Disease/Hyperacidity'=>['gastritis','acid peptic','hyperacidity','gastroenteritis','gerd'],
		'Gastrointestinal Infection/AGE/Enteritis'=>['gastroenteritis','enteritis','age'],
		'Headache (Tension headache/Migraine)'=>['headache','migraine'],
		'Hypertension'=>['hypertension','high blood'],
		'Influenza/Flu'=>['influenza','flu'],
		'Insect Bite and Sting'=>['insect bite','sting'],
		'Laryngitis /Pharyngitis'=>['laryngitis','pharyngitis','sore throat','sorethroat'],
		'Otitis Media'=>['otitis media','ear infection'],
		'Sinusitis'=>['sinusitis'],
		'Sprain'=>['sprain'],
		'Stye'=>['stye','hordeolum'],
		'Syncope/Fainting'=>['faint','syncope'],
		'Tuberculosis'=>['tuberculosis','tb'],
		'URTI'=>['upper respiratory','urti'],
		'UTI'=>['urinary tract infection','uti'],
		'Vertigo'=>['vertigo'],
		'Wound (Abrasion/Avulsed/Lacerated)'=>['wound','laceration','abrasion','avulsed']
	];

	// Medicines mapping (subset incl. main examples; extend as needed)
	$medMap = [
		'Amoxicillin 500mg'=>['amoxicillin 500mg','amoxicillin'],
		'Ibuprofen/Alaxan FR'=>['ibuprofen','alaxan'],
		'Ambroxol tablet'=>['ambroxol'],
		'Amlodipine Besilate 5mg'=>['amlodipine, besilate','Amlodipine Besilate 5mg'],
		'Antacid (Maralox/Maalox/Myrecid)'=>['antacid','maalox','maralox','myrecid'],
		'Ascorbic acid'=>['ascorbic','vitamin c','vit c'],
		'Clonidine/Nifedipine'=>['clonidine','nifedipine'],
		'Bandaid Strips'=>['bandaid','band aid','band-aid'],
		'Betadine'=>['betadine','povidone iodine'],
		'Bioflu'=>['bioflu'],
		'Biogesic/Paracetamol'=>['biogesic','paracetamol','acetaminophen'],
		'Carbocisteine (Solmux)'=>['carbocisteine','solmux'],
		'Cefalexin'=>['cefalexin','cephalexin'],
		'Cinnarizine 10mg'=>['cinnarizine'],
		'Cetirizine 10mg'=>['cetirizine','cetrizine','citrizine'],
		'Cloxacillin 500mg'=>['cloxacillin'],
		'Co-Amoxiclav 625mg'=>['co-amoxiclav','amoxiclav'],
		'Coldrex'=>['coldrex'],
		'Cotrimoxazole 800/160mg'=>['cotrimoxazole','co-trimoxazole'],
		'Diclofenac NA'=>['diclofenac'],
		'Dicycloverine'=>['dicycloverine','dicycloverine'],
		'Diphenhydramine 50mg'=>['diphenhydramine'],
		'Domperidone'=>['domperidone'],
		'Erythromycin'=>['erythromycin'],
		'Face Mask'=>['face mask','facemask'],
		'Gloves'=>['gloves'],
		'Gauze'=>['gauze'],
		'Ferrous Sulfate'=>['ferrous'],
		'Hyoscine-N-Butyl Bromide (Buscopan)'=>['buscopan','hyoscine'],
		'Isopropyl alcohol 70%'=>['isopropyl','alcohol 70'],
		'Loperamide (Immodium)'=>['loperamide','imodium','immodium'],
		'Losartan Potassium 100mg'=>['losartan'],
		'Mefenamic 500mg capsule'=>['mefenamic'],
		'Metformin 500mg'=>['metformin'],
		'Mucobron Forte capsule'=>['mucobron'],
		'Mucotoss'=>['mucotoss'],
		'Multivitamins with Iron'=>['multivitamin with iron','multivitamins with iron'],
		'Multivitamins'=>['multivitamin','multivitamins'],
		'Omeprazole 20mg'=>['omeprazole'],
		'Salbutamol Nebule'=>['salbutamol neb','nebule'],
		'Strepsils/Co-amylase'=>['strepsils'],
		'Stugeron'=>['stugeron'],
		'Silmex/Symdex'=>['symdex','silmex'],
		'Vitamin B Complex'=>['vitamin b complex','b-complex','b complex'],
		'Silver Sulfadiazine/Mupirocin'=>['silver sulfadiazine','mupirocin'],
		'Elastic Bandage'=>['elastic bandage'],
		'Efficascent oil/Pau Spray/white flower'=>['efficascent','pau','white flower'],
		'Cotton'=>['cotton'],
		'Simvastatin'=>['simvastatin'],
		'Plaster (micropore)'=>['micropore','plaster'],
		'Hydrogen peroxide'=>['hydrogen peroxide'],
		'Flu vaccine'=>['flu vaccine'],
		'Sling'=>['sling'],
		'Salonpas'=>['salonpas'],
		'Eye Mo'=>['eye mo'],
		'Hydrocortisone'=>['hydrocortisone']
	];

	// Services mapping
	$serviceMap = [
		'Wound Dressing'=>['wound dressing','dressing'],
		'Wound Suturing'=>['suturing','suture'],
		'Suture Removal'=>['suture removal','suture off'],
		'Consultation/Diagnosis & Treatment of Diseases'=>['consultation','diagnosis','treatment'],
		'Issuance of Medical Certificate'=>['medical certificate','med cert'],
		'Notification/Medical Clearance'=>['medical clearance','notification of sickness'],
		'Referral to Specialist/Consultant'=>['referral to consultant','specialist referral'],
		'Referral to Tertiary Hospital'=>['tertiary hospital','admission','confinement'],
		'Assist during Sports Events'=>['sports events','accompany athletes'],
		'Referral to Diagnostic Laboratory'=>['laboratory','cbc','urinalysis','blood chem','x-ray','ecg'],
		'Online consultation'=>['online consult'],
		'Weight Measurement'=>['weight'],
		'Height Measurement'=>['height'],
		'BP taking/BP Monitoring'=>['bp','blood pressure'],
		'FBS/RBS'=>['fbs','rbs'],
		'Oxygen Therapy/Oxygen saturation'=>['oxygen saturation','oxygen therapy'],
		'Flu Vaccine'=>['flu vaccine'],
		'Hepa B vaccination'=>['hepa b vaccine','hep b vaccination'],
		'Health Assessment'=>['health assessment','assessment only'],
		'Medical Rounds'=>['medical rounds'],
		'Pregnancy Test'=>['pregnancy test']
	];

	$illnessCounts = [];
	$medicineCounts = [];
	$serviceCounts = [];
	$roleRawCounts = ['Student'=>0,'Faculty'=>0,'Staff'=>0];
	$othersIllnessByRole = ['Student'=>0,'Faculty'=>0,'Staff'=>0];

	foreach ($rows as $r) {
		$rawRole = $hasRole ? ($r['role'] ?? '') : ($r['client_type'] ?? '');
		$debugRawRoles[] = $rawRole;
		$role = $hasRole ? $getRole($rawRole) : $getRole($rawRole);
		if (!isset($roleRawCounts[$role])) $roleRawCounts[$role]=0; $roleRawCounts[$role]++;
		$a = $norm(($r['assessment'] ?? '') . ' ' . ($r['present_illness'] ?? ''));
		$rm = $norm($r['remarks'] ?? '');

		// Illness matching (increment first matched category). If nothing matched but text exists, classify as Others.
		$matchedIll = false;
		foreach ($illnessMap as $label => $keys) {
			foreach ($keys as $k) {
				if ($k !== '' && str_contains($a, $k)) { $incr($illnessCounts,$label,$role,1); $matchedIll = true; break; }
			}
			if ($matchedIll) break;
		}
		if (!$matchedIll && $a !== '') { $incr($illnessCounts,'Others',$role,1); $othersIllnessByRole[$role]++; }

		// Services from remarks
		foreach ($serviceMap as $label => $keys) {
			foreach ($keys as $k) {
				if ($k !== '' && str_contains($rm, $k)) { $incr($serviceCounts,$label,$role,1); break; }
			}
		}

		// Medicines from remarks (parse explicit unit counts and/or per-dose x frequency x days; ignore strength like 500mg)
		$parseDoseQty = function($text) {
			// text is already lowercased (via $norm)
			// Remove strength units like 500mg, 800/160mg, 70%, etc. to avoid miscounting
			$clean = preg_replace('/\b\d+(?:\s*\/\s*\d+)?\s*mg\b/i', '', $text);
			$clean = preg_replace('/\b\d+\s*%\b/', '', $clean);

			$freq = 1; $days = 1; $freqFound = false; $daysFound = false;
			// Frequency patterns
			if (preg_match('/\b(\d+)\s*(?:x|times?)\b/i', $clean, $m)) {
				$freq = max(1, intval($m[1])); $freqFound = true;
			} elseif (preg_match('/\b(once|twice|thrice)\b/i', $clean, $m)) {
				$map = ['once'=>1,'twice'=>2,'thrice'=>3];
				$freq = $map[strtolower($m[1])] ?? 1; $freqFound = true;
			} elseif (preg_match('/\b(od|qd|bid|tid|qid)\b/i', $clean, $m)) {
				$m0 = strtolower($m[1]);
				$freq = ['od'=>1,'qd'=>1,'bid'=>2,'tid'=>3,'qid'=>4][$m0] ?? 1; $freqFound = true;
			} elseif (preg_match('/\bq\s*(\d+)\s*(?:h|hr|hrs|hour|hours)\b/i', $clean, $m)) {
				$h = max(1, intval($m[1]));
				$freq = max(1, min(24, (int) round(24 / $h))); $freqFound = true;
			}

			// Days/duration patterns
			if (preg_match('/\b(?:for|x|times|good for)\s*(\d+)\s*(?:day|days|d)\b/i', $clean, $m)) {
				$days = max(1, intval($m[1])); $daysFound = true;
			} elseif (preg_match('/\b(?:for|x|times|good for)\s*(\d+)\s*(?:week|weeks|w)\b/i', $clean, $m)) {
				$days = max(1, intval($m[1])) * 7; $daysFound = true;
			} elseif (preg_match('/\b(\d+)\s*(?:day|days|d)\b/i', $clean, $m)) {
				// fallback if "for" not explicitly present
				$days = max(1, intval($m[1])); $daysFound = true;
			} elseif (preg_match('/\b(\d+)\s*(?:week|weeks|w)\b/i', $clean, $m)) {
				$days = max(1, intval($m[1])) * 7; $daysFound = true;
			}

			// Unit patterns: explicit total units or per-dose units
			$unitPattern = '/\b(\d+)\s*(tab(?:s)?|tablet(?:s)?|cap(?:s)?|capsule(?:s)?|pc(?:s)?|piece(?:s)?|sachet(?:s)?|bottle(?:s)?|vial(?:s)?|amp(?:oule)?(?:s)?)\b/i';
			$perDosePattern = '/\b(\d+)\s*(tab(?:s)?|tablet(?:s)?|cap(?:s)?|capsule(?:s)?)\b/i';

			$explicitTotals = [];
			if (preg_match_all($unitPattern, $clean, $mm, PREG_SET_ORDER)) {
				foreach ($mm as $m) {
					$n = intval($m[1]);
					$u = strtolower($m[2]);
					// Treat as explicit total if no frequency/duration detected
					if (!$freqFound && !$daysFound) { $explicitTotals[] = $n; }
				}
			}
			if (!empty($explicitTotals)) {
				// If multiple explicit numbers, take the largest as conservative total
				return max(1, max($explicitTotals));
			}

			// Else compute per-dose x frequency x days (default per-dose = 1 if absent)
			$perDose = 1;
			if (preg_match($perDosePattern, $clean, $m)) {
				$perDose = max(1, intval($m[1]));
			}
			return max(1, $perDose * $freq * $days);
		};

		foreach ($medMap as $label => $keys) {
			foreach ($keys as $k) {
				if ($k === '') continue;
				$kLower = strtolower($k);
				if (str_contains($rm, $kLower)) {
					// Prefer parsing within a nearby window around the medicine mention to avoid mixing with other meds
					$pos = strpos($rm, $kLower);
					$window = $rm;
					if ($pos !== false) {
						$wStart = max(0, $pos - 20); // local var so we don't clobber date $start
						$len = 160; // look ahead ~160 chars from just before the med name
						$window = substr($rm, $wStart, $len);
					}
					$qty = $parseDoseQty($window);
					$incr($medicineCounts,$label,$role,$qty);
					break;
				}
			}
		}
	}

	// Build ordered rows (keep input order where possible)
	$rolesIdx = ['Student','Faculty','Staff'];
	$sumRole = function($arr) use($rolesIdx){ $tot = array_fill_keys($rolesIdx,0); foreach($arr as $row){ foreach($rolesIdx as $r){ $tot[$r] += (int)($row[$r] ?? 0); } } return $tot; };

	$medRows = [];
	foreach ($medMap as $label => $_) { $medRows[] = ['label'=>$label] + ($medicineCounts[$label] ?? array_fill_keys($rolesIdx,0)); }
	$medTotals = $sumRole(array_map(function($r){ return ['Student'=>$r['Student']??0,'Faculty'=>$r['Faculty']??0,'Staff'=>$r['Staff']??0]; }, $medRows));

	$svcRows = [];
	foreach ($serviceMap as $label => $_) { $svcRows[] = ['label'=>$label] + ($serviceCounts[$label] ?? array_fill_keys($rolesIdx,0)); }
	$svcTotals = $sumRole(array_map(function($r){ return ['Student'=>$r['Student']??0,'Faculty'=>$r['Faculty']??0,'Staff'=>$r['Staff']??0]; }, $svcRows));

	$illRows = [];
	foreach ($illnessMap as $label => $_) { $illRows[] = ['label'=>$label] + ($illnessCounts[$label] ?? array_fill_keys($rolesIdx,0)); }
	$illTotals = $sumRole(array_map(function($r){ return ['Student'=>$r['Student']??0,'Faculty'=>$r['Faculty']??0,'Staff'=>$r['Staff']??0]; }, $illRows));

	// Top 5 per role based on illness counts
	$topByRole = [];
	foreach ($rolesIdx as $r) {
		$pairs = [];
		foreach ($illRows as $row) { $pairs[] = ['label'=>$row['label'], 'count'=> (int)($row[$r] ?? 0)]; }
		usort($pairs, fn($a,$b)=> $b['count'] <=> $a['count']);
		$topByRole[$r] = array_slice(array_map(fn($p)=> $p['label'], array_filter($pairs, fn($p)=> $p['count']>0)), 0, 5);
	}

	// Cases seen
	$withIllness = $illTotals; // sum of illnesses implies with-illness
	$otherServices = $svcTotals; // proxy for other services
	$grandTotal = ['Student'=> ($withIllness['Student']+$otherServices['Student']), 'Faculty'=> ($withIllness['Faculty']+$otherServices['Faculty']), 'Staff'=> ($withIllness['Staff']+$otherServices['Staff']) ];

	echo json_encode([
		'success'=>true,
		'period'=>['start'=>$start,'end'=>$end],
		'hasRole'=>$hasLegacyRole,
		'medicines'=>['rows'=>$medRows,'totals'=>$medTotals, 'grand'=> (int)$medTotals['Student'] + (int)$medTotals['Faculty'] + (int)$medTotals['Staff']],
		'services'=>['rows'=>$svcRows,'totals'=>$svcTotals, 'grand'=> (int)$svcTotals['Student'] + (int)$svcTotals['Faculty'] + (int)$svcTotals['Staff']],
		'illnesses'=>['rows'=>$illRows,'totals'=>$illTotals,'topByRole'=>$topByRole, 'grand'=> (int)$illTotals['Student'] + (int)$illTotals['Faculty'] + (int)$illTotals['Staff']],
		'casesSeen'=>[
			'withIllness'=>$withIllness,
			'otherServices'=>$otherServices,
			'grandTotal'=>$grandTotal
		],
		'debug'=>[
			'rawRoles'=>$debugRawRoles,
			'uniqueRawRoles'=>array_values(array_unique($debugRawRoles)),
			'roleRawCounts'=>$roleRawCounts,
			'othersIllnessByRole'=>$othersIllnessByRole
		]
	]);
	exit;
}

// Raw debug for checkups (date range + basic columns)
if ($action === 'debug_checkups') {
	$start = $_GET['start'] ?? date('Y-m-01');
	$end = $_GET['end'] ?? date('Y-m-t');
	try {
		// Detect whether legacy 'role' column exists; if not, safely select client_type as role
		$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
		$hasRoleCol = false;
		if ($dbName) {
			$cstmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'checkups' AND COLUMN_NAME = 'role'");
			$cstmt->execute([$dbName]);
			$hasRoleCol = intval($cstmt->fetchColumn()) > 0;
		}
		if ($hasRoleCol) {
			$sql = "SELECT id, student_faculty_id, name, COALESCE(NULLIF(client_type,''), role) AS role, assessment, present_illness, remarks, created_at FROM checkups WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 300";
		} else {
			$sql = "SELECT id, student_faculty_id, name, client_type AS role, assessment, present_illness, remarks, created_at FROM checkups WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 300";
		}
		$stmt = $pdo->prepare($sql);
		$stmt->execute([$start, $end]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		echo json_encode(['success'=>true,'start'=>$start,'end'=>$end,'count'=>count($rows),'rows'=>$rows]);
	} catch (Throwable $e) {
		echo json_encode(['success'=>false,'message'=>'debug_checkups failed','error'=>$e->getMessage()]);
	}
	exit;
}

// Add other report actions as needed, matching the standardized patient fields

echo json_encode(['error' => 'Invalid action']);
