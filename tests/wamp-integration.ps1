param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string]$MysqlExe = 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe',
    [string]$MysqldumpExe = 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysqldump.exe',
    [string]$PhpExe = 'C:\wamp64\bin\php\php8.4.15\php.exe',
    [string]$SourceDatabase = 'smart blood bank'
)

$ErrorActionPreference = 'Stop'
$mysqlBase = @('--host=127.0.0.1', '--port=3306', '--user=root')
$stamp = Get-Date -Format 'yyyyMMddHHmmss'
$testDatabase = "sbb_it_$stamp"
$dumpFile = Join-Path $env:TEMP "sbb-it-$stamp.sql"
$serverOut = Join-Path $env:TEMP "sbb-it-$stamp-out.log"
$serverErr = Join-Path $env:TEMP "sbb-it-$stamp-err.log"
$sessionDir = Join-Path $env:TEMP "sbb-php-session-$stamp"
$server = $null
$oldDbOverride = $env:SBB_DB_NAME

function Assert-Check([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "CHECK FAILED: $Message" }
    Write-Host "PASS: $Message"
}

function Invoke-Api([string]$Path, [Microsoft.PowerShell.Commands.WebRequestSession]$Session, [string]$Method = 'GET', $Body = $null) {
    $params = @{
        Uri = "http://127.0.0.1:$script:Port/$Path"
        Method = $Method
        WebSession = $Session
        TimeoutSec = 20
        ErrorAction = 'Stop'
    }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json'
        $params.Body = ConvertTo-Json -InputObject $Body -Depth 8 -Compress
    }
    try {
        $params.UseBasicParsing = $true
        $response = Invoke-WebRequest @params
        return [pscustomobject]@{ Status = [int]$response.StatusCode; Json = ($response.Content | ConvertFrom-Json) }
    } catch {
        $status = 0
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        $json = $null
        if ($_.ErrorDetails.Message) {
            try { $json = $_.ErrorDetails.Message | ConvertFrom-Json } catch { }
        }
        return [pscustomobject]@{ Status = $status; Json = $json }
    }
}

function Login([string]$Identity, [string]$Password) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $response = Invoke-Api 'api/login.php' $session 'POST' @{ identity = $Identity; password = $Password }
    Assert-Check ($response.Status -eq 200 -and $response.Json.success) "login for $Identity"
    return $session
}

function Run-Mysql([string]$Sql, [switch]$Quiet) {
    $output = & $script:MysqlExe @script:mysqlBase --database=$script:testDatabase --batch --skip-column-names -e $Sql
    if ($LASTEXITCODE -ne 0) { throw "MySQL test-database query failed: $output" }
    return $output
}

try {
    foreach ($path in @($MysqlExe, $MysqldumpExe, $PhpExe)) {
        if (-not (Test-Path -LiteralPath $path)) { throw "Required WAMP executable not found: $path" }
    }

    $collision = & $MysqlExe @mysqlBase --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$testDatabase';"
    if ($LASTEXITCODE -ne 0 -or [int]$collision -ne 0) { throw 'Could not reserve a unique temporary database name.' }

    & $MysqldumpExe @mysqlBase --single-transaction --triggers --result-file=$dumpFile $SourceDatabase
    if ($LASTEXITCODE -ne 0 -or (Get-Item -LiteralPath $dumpFile).Length -le 0) { throw 'Could not create a nonempty source database dump.' }
    & $MysqlExe @mysqlBase -e "CREATE DATABASE $testDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the isolated integration database.' }
    $sourcePath = $dumpFile.Replace('\', '/')
    & $MysqlExe @mysqlBase --database=$testDatabase -e "source $sourcePath"
    if ($LASTEXITCODE -ne 0) { throw 'Could not restore the source database to the isolated integration database.' }
    Run-Mysql 'ALTER TABLE users ENGINE=InnoDB; ALTER TABLE contact_messages ENGINE=InnoDB; ALTER TABLE donors ENGINE=InnoDB; ALTER TABLE blood_units ENGINE=InnoDB; ALTER TABLE blood_requests ENGINE=InnoDB; ALTER TABLE transactions ENGINE=InnoDB; ALTER TABLE audit_logs ENGINE=InnoDB; ALTER TABLE settings ENGINE=InnoDB;' -Quiet | Out-Null
    $triggerCount = Run-Mysql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$testDatabase' AND EVENT_OBJECT_TABLE='audit_logs';" -Quiet
    Assert-Check ([int]$triggerCount -eq 2) 'audit immutability triggers survive transactional engine migration'

    $fixtureSql = @'
SET @staff_a := (SELECT user_id FROM users WHERE email = 'staff@bloodbank.pk' LIMIT 1);
INSERT INTO users (username, full_name, email, phone, password_hash, role, blood_group, institution_id, institution_verified)
VALUES ('btest$stamp', 'Facility B Test', 'btest$stamp@example.test', '03000000000', '$2y$10$4OdqEQXMrw5gotQewF4HB.LfTuk4P1pXIFZSBrzVbxbKdsGNJUlJq', 'STAFF', 'A+', '137', 1);
SET @staff_b := LAST_INSERT_ID();
INSERT INTO users (username, full_name, email, phone, password_hash, role, blood_group, institution_id, institution_verified)
VALUES ('atest$stamp', 'Facility A Admin Test', 'atest$stamp@example.test', '03000000003', '$2y$10$4OdqEQXMrw5gotQewF4HB.LfTuk4P1pXIFZSBrzVbxbKdsGNJUlJq', 'ADMIN', 'A+', 'FUSST-BB', 1);
INSERT INTO users (username, full_name, email, phone, password_hash, role, blood_group, institution_id, institution_verified)
VALUES ('adminb$stamp', 'Facility B Admin Test', 'adminb$stamp@example.test', '03000000004', '$2y$10$4OdqEQXMrw5gotQewF4HB.LfTuk4P1pXIFZSBrzVbxbKdsGNJUlJq', 'ADMIN', 'A+', '137', 1);
INSERT INTO donors (managed_by, name, contact_details, blood_group) VALUES (@staff_b, 'Facility B Fixture Donor', 'fixture only', 'A_POSITIVE');
SET @donor_b := LAST_INSERT_ID();
INSERT INTO blood_units (donor_id, blood_group, collection_date, expiry_date, status, institution_id)
VALUES (@donor_b, 'A_POSITIVE', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 20 DAY), 'QUARANTINED', 137);
SET @unit_b := LAST_INSERT_ID();
INSERT INTO blood_requests (submitted_by, serving_institution_id, requested_blood_group, quantity, urgency, status, request_date, patient_ref)
VALUES (@staff_b, 137, 'A_POSITIVE', 1, 'ROUTINE', 'PENDING', NOW(), 'FACILITY-B-FIXTURE');
SET @request_b := LAST_INSERT_ID();
INSERT INTO audit_logs (user_id, action, entity_id, details) VALUES (@staff_b, 'FACILITY_B_PRIVATE_MARKER', @request_b, 'Fixture privacy marker');
SELECT @staff_b, @unit_b, @request_b;
'@
    $fixtureSql = $fixtureSql -replace '\$stamp', $stamp
    $fixture = Run-Mysql $fixtureSql
    $fixtureValues = ($fixture | Select-Object -Last 1).Trim() -split "`t"
    if ($fixtureValues.Count -lt 3) { throw 'Could not read fixture identifiers from isolated database.' }
    $facilityBUnit = [int]$fixtureValues[1]
    $facilityBRequest = [int]$fixtureValues[2]

    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $script:Port = $listener.LocalEndpoint.Port
    $listener.Stop()

    New-Item -ItemType Directory -Path $sessionDir -ErrorAction Stop | Out-Null
    $env:SBB_DB_NAME = $testDatabase
    $phpSessionPath = $sessionDir.Replace('\', '/')
    $server = Start-Process -FilePath $PhpExe -ArgumentList @('-d', "session.save_path=$phpSessionPath", '-S', "127.0.0.1:$Port", '-t', $ProjectRoot) -WorkingDirectory $ProjectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput $serverOut -RedirectStandardError $serverErr
    $ready = $false
    for ($i = 0; $i -lt 40; $i++) {
        if ($server.HasExited) { throw "PHP test server exited early. See $serverErr" }
        try { Invoke-WebRequest "http://127.0.0.1:$Port/api/session.php" -UseBasicParsing -TimeoutSec 1 -ErrorAction Stop | Out-Null; $ready = $true; break } catch { Start-Sleep -Milliseconds 250 }
    }
    if (-not $ready) {
        $startupError = if (Test-Path -LiteralPath $serverErr) { Get-Content -LiteralPath $serverErr -Raw } else { '(no PHP server error output)' }
        throw "PHP test server did not start. See $serverErr`n$startupError"
    }

    $anonymous = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $weakRegistration = Invoke-Api 'api/register.php' $anonymous 'POST' @{
        fullName = 'Integration Requester'; username = "r$stamp"; email = "r$stamp@example.test";
        phone = '03000000001'; institutionId = 'HOSP-TEST'; bloodGroup = 'O+'; password = 'weakpass'
    }
    Assert-Check ($weakRegistration.Status -eq 400) 'registration rejects a weak password at the API boundary'
    $forbiddenAdminRegistration = Invoke-Api 'api/register.php' $anonymous 'POST' @{
        fullName = 'Forbidden Admin'; username = "x$stamp"; email = "x$stamp@example.test";
        phone = '03000000011'; institutionId = 'FUSST-BB'; role = 'ADMIN'; bloodGroup = 'O+'; password = 'TestPass!123'
    }
    Assert-Check ($forbiddenAdminRegistration.Status -eq 400) 'public registration rejects Admin as a role choice'
    $adminA = Login "atest$stamp" 'Staff@123'
    $registration = Invoke-Api 'api/register.php' $anonymous 'POST' @{
        fullName = 'Integration Requester'; username = "r$stamp"; email = "r$stamp@example.test";
        phone = '03000000001'; institutionId = 'HOSP-TEST'; role = 'HOSPITAL_REQUESTER'; bloodGroup = 'O+'; password = 'TestPass!123'
    }
    Assert-Check ($registration.Status -eq 202 -and $registration.Json.approval_status -eq 'PENDING' -and -not $registration.Json.user) 'hospital role application is pending and receives no authenticated session'
    $preApprovalSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $preApprovalLogin = Invoke-Api 'api/login.php' $preApprovalSession 'POST' @{ identity = "r$stamp"; password = 'TestPass!123' }
    Assert-Check ($preApprovalLogin.Status -eq 403) 'hospital applicant cannot sign in before Admin approval'
    $requesterId = [int](Run-Mysql "SELECT user_id FROM users WHERE username='r$stamp';" -Quiet)
    $requesterApproval = Invoke-Api 'api/account-requests.php' $adminA 'POST' @{ user_id = $requesterId; decision = 'APPROVE' }
    Assert-Check ($requesterApproval.Status -eq 200 -and $requesterApproval.Json.approval_status -eq 'APPROVED') 'Admin can approve a pending hospital account application'
    $requester = Login "r$stamp" 'TestPass!123'
    $registrationSession = Invoke-Api 'api/session.php' $requester
    Assert-Check ($registrationSession.Json.authenticated -and $registrationSession.Json.user.role -eq 'HOSPITAL_REQUESTER') 'approved hospital requester can sign in with its approved role'
    $basicRegistrationSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $basicRegistration = Invoke-Api 'api/register.php' $basicRegistrationSession 'POST' @{
        fullName = 'Regular Integration User'; username = "u$stamp"; email = "u$stamp@example.test";
        phone = '03000000010'; role = 'USER'; password = 'TestPass!123'
    }
    Assert-Check ($basicRegistration.Status -eq 201 -and $basicRegistration.Json.user.role -eq 'USER') 'regular User account is available immediately'
    $newRequest = Invoke-Api 'api/hospital.php' $requester 'POST' @{ action = 'place_requisition'; blood_group = 'A+'; quantity = 2; urgency = 'URGENT'; patient_ref = 'INTEGRATION-PARTIAL'; clinical_indication = 'Elective Surgery' }
    Assert-Check ($newRequest.Status -eq 201 -and $newRequest.Json.success) "requester can place a two-unit hospital requisition (HTTP $($newRequest.Status): $($newRequest.Json.message))"
    $invalidQuantity = Invoke-Api 'api/hospital.php' $requester 'POST' @{ action = 'place_requisition'; blood_group = 'A+'; quantity = 1.5; urgency = 'URGENT'; patient_ref = 'INTEGRATION-INVALID'; clinical_indication = 'Elective Surgery' }
    Assert-Check ($invalidQuantity.Status -eq 422) 'hospital API rejects fractional requisition quantities'
    $partialRequest = [int]$newRequest.Json.request_id
    $requesterOrders = Invoke-Api 'api/hospital.php?action=my_requests' $requester
    Assert-Check (@($requesterOrders.Json.orders | Where-Object { $_.request_id -eq $partialRequest }).Count -eq 1) 'requester sees their own requisition'

    $candidateSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $candidateUsername = "p$stamp"
    $candidateRegistration = Invoke-Api 'api/register.php' $candidateSession 'POST' @{
        fullName = 'Admin Promotion Candidate'; username = $candidateUsername; email = "$candidateUsername@example.test";
        phone = '03000000007'; institutionId = ''; role = 'USER'; bloodGroup = ''; password = 'CandidatePass!123'
    }
    Assert-Check ($candidateRegistration.Status -eq 201 -and $candidateRegistration.Json.user.role -eq 'USER') 'admin promotion candidate starts as a regular User'
    $selfPromotion = Invoke-Api 'api/register.php' $candidateSession 'POST' @{ action = 'promote_to_admin'; username_or_email = $candidateUsername }
    Assert-Check ($selfPromotion.Status -eq 403) 'regular User cannot promote themselves to Admin'

    $staffA = Login 'staff@bloodbank.pk' 'Staff@123'
    $staffB = Login "btest$stamp" 'Staff@123'
    $profileUpdate = Invoke-Api 'api/profile.php' $staffA 'POST' @{ action = 'update_profile'; full_name = 'Demo Staff Integration'; phone = '03000000005' }
    $profileRead = Invoke-Api 'api/profile.php?action=get_profile' $staffA
    Assert-Check ($profileUpdate.Status -eq 200 -and $profileRead.Json.user.full_name -eq 'Demo Staff Integration') 'users can update and read back their own profile'
    $adminB = Login "adminb$stamp" 'Staff@123'
    $promotion = Invoke-Api 'api/register.php' $adminA 'POST' @{ action = 'promote_to_admin'; username_or_email = $candidateUsername }
    Assert-Check ($promotion.Status -eq 200 -and $promotion.Json.user.role -eq 'ADMIN' -and $promotion.Json.user.institutionId -eq 'FUSST-BB') 'admin can promote a regular User into the admin facility'
    $promotedLogin = Login $candidateUsername 'CandidatePass!123'
    $promotedSession = Invoke-Api 'api/session.php' $promotedLogin
    Assert-Check ($promotedSession.Json.authenticated -and $promotedSession.Json.user.role -eq 'ADMIN') 'promoted regular User receives Admin access on a fresh sign-in'
    $promotionAudit = Invoke-Api 'api/audit.php' $adminA
    Assert-Check (@($promotionAudit.Json.logs | Where-Object { $_.action -eq 'USER_ROLE_PROMOTED' -and $_.entity_id -eq $promotion.Json.user.userId }).Count -eq 1) 'Admin promotion is recorded in the facility audit trail'
    $crossFacilityReset = Invoke-Api 'api/admin-password-reset.php' $adminB 'POST' @{ username_or_email = $candidateUsername }
    Assert-Check ($crossFacilityReset.Status -eq 404) 'admin password reset cannot target an account in another facility'
    $passwordReset = Invoke-Api 'api/admin-password-reset.php' $adminA 'POST' @{ username_or_email = $candidateUsername }
    $temporaryPassword = [string]$passwordReset.Json.temporary_password
    Assert-Check ($passwordReset.Status -eq 200 -and $temporaryPassword.Length -ge 20 -and $passwordReset.Json.must_change_password) 'admin receives a one-time temporary password for an account in their facility'
    $resetAudit = Invoke-Api 'api/audit.php' $adminA
    $resetLog = @($resetAudit.Json.logs | Where-Object { $_.action -eq 'ADMIN_PASSWORD_RESET' -and $_.entity_id -eq $promotion.Json.user.userId })
    Assert-Check ($resetLog.Count -eq 1 -and $resetLog[0].details -notmatch [regex]::Escape($temporaryPassword)) 'password reset is audited without recording the temporary password'
    $staleSessionInventory = Invoke-Api 'api/inventory.php' $promotedLogin
    Assert-Check ($staleSessionInventory.Status -eq 423) 'password reset also locks the users already-open session'
    $temporarySession = Login $candidateUsername $temporaryPassword
    $temporarySessionInfo = Invoke-Api 'api/session.php' $temporarySession
    $blockedInventory = Invoke-Api 'api/inventory.php' $temporarySession
    $blockedProfileRead = Invoke-Api 'api/profile.php?action=get_profile' $temporarySession
    Assert-Check ($temporarySessionInfo.Json.user.mustChangePassword -and $blockedInventory.Status -eq 423 -and $blockedProfileRead.Status -eq 423) 'reset user is forced to the password-change screen and protected APIs are locked'
    $changedPassword = Invoke-Api 'api/profile.php' $temporarySession 'POST' @{ action = 'change_password'; old_password = $temporaryPassword; new_password = 'ReplacementPass!123'; confirm_password = 'ReplacementPass!123' }
    Assert-Check ($changedPassword.Status -eq 200 -and $changedPassword.Json.success) 'user can replace the temporary password'
    $oldTemporarySession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $oldTemporaryLogin = Invoke-Api 'api/login.php' $oldTemporarySession 'POST' @{ identity = $candidateUsername; password = $temporaryPassword }
    $replacementLogin = Login $candidateUsername 'ReplacementPass!123'
    $replacementInventory = Invoke-Api 'api/inventory.php' $replacementLogin
    Assert-Check ($oldTemporaryLogin.Status -eq 401 -and $replacementInventory.Status -eq 200) 'temporary password stops working and the new password restores application access'
    $provisioned = Invoke-Api 'api/register.php' $adminA 'POST' @{
        fullName = 'Provisioned Lab Tech'; username = "lab$stamp"; email = "lab$stamp@example.test";
        phone = '03000000006'; institutionId = '137'; role = 'LAB_TECHNICIAN'; bloodGroup = 'A+'; password = 'TestPass!123'
    }
    Assert-Check ($provisioned.Status -eq 201 -and $provisioned.Json.user.role -eq 'LAB_TECHNICIAN' -and $provisioned.Json.user.institutionId -eq 'FUSST-BB') 'admin can provision a lab role only within the admin facility'
    $saveThresholdA = Invoke-Api 'api/profile.php' $adminA 'POST' @{ action = 'save_thresholds'; thresholds = @{ A_POSITIVE = 2; NEAR_EXPIRY_DAYS = 11 } }
    $saveThresholdB = Invoke-Api 'api/profile.php' $adminB 'POST' @{ action = 'save_thresholds'; thresholds = @{ A_POSITIVE = 9; NEAR_EXPIRY_DAYS = 20 } }
    $thresholdA = Invoke-Api 'api/profile.php?action=get_thresholds' $adminA
    $thresholdB = Invoke-Api 'api/profile.php?action=get_thresholds' $adminB
    Assert-Check ($saveThresholdA.Status -eq 200 -and $saveThresholdB.Status -eq 200 -and $thresholdA.Json.thresholds.A_POSITIVE -eq 2 -and $thresholdB.Json.thresholds.A_POSITIVE -eq 9) 'administrator alert thresholds are facility-specific'
    $inventoryA = Invoke-Api 'api/inventory.php' $staffA
    $inventoryB = Invoke-Api 'api/inventory.php' $staffB
    $expectedBUnits = @((Run-Mysql 'SELECT GROUP_CONCAT(unit_id ORDER BY unit_id) FROM blood_units WHERE institution_id=137;' -Quiet).Trim() -split ',' | ForEach-Object { [int]$_ })
    $actualBUnits = @($inventoryB.Json.units | ForEach-Object { [int]$_.unit_id } | Sort-Object)
    $facilityBUnitsVisibleToA = @($inventoryA.Json.units | Where-Object { [int]$_.unit_id -eq $facilityBUnit })
    Assert-Check ($inventoryA.Status -eq 200 -and $facilityBUnitsVisibleToA.Count -eq 0) 'facility A inventory excludes facility B units'
    Assert-Check ($inventoryB.Status -eq 200 -and [string]::Join(',', $actualBUnits) -eq [string]::Join(',', $expectedBUnits)) 'facility B inventory contains only its own units'

    $donorA = Invoke-Api 'api/donors.php' $staffA 'POST' @{ name = 'Facility A Fixture Donor'; contact_details = '03000000008'; blood_group = 'A+' }
    Assert-Check ($donorA.Status -eq 201 -and $donorA.Json.success) 'staff can create a donor record'
    $donorsA = Invoke-Api 'api/donors.php' $staffA
    $donorsB = Invoke-Api 'api/donors.php' $staffB
    Assert-Check (@($donorsA.Json.donors | Where-Object { $_.name -eq 'Facility A Fixture Donor' }).Count -eq 1 -and @($donorsB.Json.donors | Where-Object { $_.name -eq 'Facility A Fixture Donor' }).Count -eq 0) 'donor records are scoped to their managing facility'
    $donation = Invoke-Api 'api/inventory.php' $staffA 'POST' @{ action = 'log_donation'; donor_id = [int]$donorA.Json.donor_id; collection_date = (Get-Date -Format 'yyyy-MM-dd'); shelf_life_days = 35; blood_group = 'A+' }
    Assert-Check ($donation.Status -eq 201 -and $donation.Json.success) 'staff can log a donation into quarantine'
    $facilityAUnit = [int]$donation.Json.unit_id
    $screened = @{ screen_hiv = $true; screen_hbv = $true; screen_hcv = $true; screen_syphilis = $true; screen_malaria = $true; verify_abo = $true; clearance_notes = 'Integration screen panel verified.' }
    $wrongLab = Invoke-Api 'api/lab.php' $staffB 'POST' ($screened + @{ action = 'clear_unit'; unit_id = $facilityAUnit })
    Assert-Check ($wrongLab.Status -eq 404) 'facility B laboratory cannot clear a facility A unit'
    $labTech = Login "lab$stamp" 'TestPass!123'
    $clearance = Invoke-Api 'api/lab.php' $labTech 'POST' ($screened + @{ action = 'clear_unit'; unit_id = $facilityAUnit })
    Assert-Check ($clearance.Status -eq 200 -and $clearance.Json.success) 'lab clearance makes an owned quarantined unit available'

    $requestsA = Invoke-Api 'api/allocation.php?action=requests' $staffA
    $requestsB = Invoke-Api 'api/allocation.php?action=requests' $staffB
    Assert-Check (@($requestsA.Json.requests | Where-Object { [int]$_.request_id -eq $facilityBRequest }).Count -eq 0) 'facility A request queue excludes facility B requests'
    Assert-Check (@($requestsB.Json.requests | Where-Object { [int]$_.request_id -eq $partialRequest }).Count -eq 0 -and @($requestsB.Json.requests | Where-Object { [int]$_.request_id -eq $facilityBRequest }).Count -eq 1) 'facility B request queue contains only its own fixture request'

    $recommendationAtoB = Invoke-Api "api/allocation.php?action=recommendations&request_id=$facilityBRequest" $staffA
    $recommendationBtoA = Invoke-Api "api/allocation.php?action=recommendations&request_id=$partialRequest" $staffB
    Assert-Check ($recommendationAtoB.Status -eq 404 -and $recommendationBtoA.Status -eq 404) 'recommendations reject cross-facility request IDs'
    $slipAtoB = Invoke-Api "api/hospital.php?action=transfusion_slip&request_id=$facilityBRequest" $staffA
    $slipBtoA = Invoke-Api "api/hospital.php?action=transfusion_slip&request_id=$partialRequest" $staffB
    Assert-Check ($slipAtoB.Status -eq 404 -and $slipBtoA.Status -eq 404) 'transfusion slips reject cross-facility request IDs'
    $auditA = Invoke-Api 'api/audit.php' $staffA
    $auditB = Invoke-Api 'api/audit.php' $staffB
    Assert-Check (@($auditA.Json.logs | Where-Object { $_.details -match 'Fixture privacy marker' }).Count -eq 0) 'facility A audit view excludes facility B activity'
    Assert-Check (@($auditB.Json.logs | Where-Object { $_.details -match 'Fixture privacy marker' }).Count -eq 1) 'facility B audit view includes its own activity'

    $crossAllocate = Invoke-Api 'api/allocation.php' $staffA 'POST' @{ action = 'confirm_allocation'; request_id = $facilityBRequest; unit_ids = @([int]$inventoryA.Json.units[0].unit_id) }
    Assert-Check ($crossAllocate.Status -eq 404) 'allocation rejects cross-facility request IDs'

    $recommendations = Invoke-Api "api/allocation.php?action=recommendations&request_id=$partialRequest" $staffA
    Assert-Check ($recommendations.Status -eq 200 -and $recommendations.Json.recommendations.Count -ge 2) 'partial request has two compatible live candidates'
    $firstUnit = [int]$recommendations.Json.recommendations[0].unit_id
    $firstAllocation = Invoke-Api 'api/allocation.php' $staffA 'POST' @{ action = 'confirm_allocation'; request_id = $partialRequest; unit_ids = @($firstUnit) }
    Assert-Check ($firstAllocation.Status -eq 200 -and $firstAllocation.Json.status -eq 'PENDING' -and $firstAllocation.Json.remaining -eq 1) 'first allocation leaves a partial request pending'
    $nextRecommendations = Invoke-Api "api/allocation.php?action=recommendations&request_id=$partialRequest" $staffA
    $secondUnit = [int]$nextRecommendations.Json.recommendations[0].unit_id
    $secondAllocation = Invoke-Api 'api/allocation.php' $staffA 'POST' @{ action = 'confirm_allocation'; request_id = $partialRequest; unit_ids = @($secondUnit) }
    Assert-Check ($secondAllocation.Status -eq 200 -and $secondAllocation.Json.status -eq 'FULFILLED' -and $secondAllocation.Json.remaining -eq 0) 'second allocation fulfills the request exactly'
    $slip = Invoke-Api "api/hospital.php?action=transfusion_slip&request_id=$partialRequest" $staffA
    Assert-Check ($slip.Status -eq 200 -and $slip.Json.order.status -eq 'FULFILLED' -and $slip.Json.allocated_units.Count -eq 2) 'completed transfusion slip shows both allocated units'

    $bClear = Invoke-Api 'api/lab.php' $staffA 'POST' ($screened + @{ action = 'clear_unit'; unit_id = $facilityBUnit })
    Assert-Check ($bClear.Status -eq 404) 'lab clearance rejects a unit from another facility'
    $bOwnClear = Invoke-Api 'api/lab.php' $staffB 'POST' ($screened + @{ action = 'clear_unit'; unit_id = $facilityBUnit })
    Assert-Check ($bOwnClear.Status -eq 200 -and $bOwnClear.Json.success) 'facility B lab staff can clear its own quarantined unit'

    $today = Get-Date -Format 'yyyy-MM-dd'
    $monthStart = Get-Date -Day 1 -Format 'yyyy-MM-dd'
    $reportA = Invoke-Api "api/reports.php?start_date=$monthStart&end_date=$today" $staffA
    $reportB = Invoke-Api "api/reports.php?start_date=$monthStart&end_date=$today" $staffB
    Assert-Check ($reportA.Status -eq 200 -and $reportB.Status -eq 200 -and $reportB.Json.demand_metrics.total_requests -eq 1 -and $reportB.Json.report_period.start_date -eq $monthStart -and $reportB.Json.report_period.end_date -eq $today) "custom report dates apply to facility-scoped request totals (A=$($reportA.Status)/$($reportA.Json.message), B=$($reportB.Status)/$($reportB.Json.message), count=$($reportB.Json.demand_metrics.total_requests), range=$($reportB.Json.report_period.start_date)..$($reportB.Json.report_period.end_date), expected=$monthStart..$today)"
    $invalidReportPeriod = Invoke-Api "api/reports.php?start_date=$((Get-Date).AddDays(1).ToString('yyyy-MM-dd'))&end_date=$today" $staffA
    Assert-Check ($invalidReportPeriod.Status -eq 422) 'reports reject reversed date ranges'

    Run-Mysql "CREATE TRIGGER sbb_it_fail_audit_insert BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced audit failure check';" -Quiet
    $failedUser = "f$stamp"
    $failedRegistration = Invoke-Api 'api/register.php' $anonymous 'POST' @{
        fullName = 'Audit Failure Rollback'; username = $failedUser; email = "$failedUser@example.test";
        phone = '03000000002'; institutionId = 'HOSP-TEST'; role = 'ADMIN'; bloodGroup = 'O+'; password = 'TestPass!123'
    }
    $persisted = Run-Mysql "SELECT COUNT(*) FROM users WHERE username='$failedUser';" -Quiet
    Assert-Check ($failedRegistration.Status -eq 500 -and [int]$persisted -eq 0) "audit insert failure returns an error and rolls back the associated user write (HTTP $($failedRegistration.Status), persisted=$persisted, response=$($failedRegistration.Json.message))"
    $failedDonor = Invoke-Api 'api/donors.php' $staffA 'POST' @{ name = 'Audit Failure Donor'; contact_details = '03000000009'; blood_group = 'A+' }
    $persistedDonor = Run-Mysql "SELECT COUNT(*) FROM donors WHERE name='Audit Failure Donor';" -Quiet
    Assert-Check ($failedDonor.Status -eq 500 -and [int]$persistedDonor -eq 0) 'audit insert failure also rolls back a donor creation'

    Write-Output 'WAMP integration checks passed against an isolated restored database.'
} finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
    if ($null -eq $oldDbOverride) { Remove-Item Env:SBB_DB_NAME -ErrorAction SilentlyContinue } else { $env:SBB_DB_NAME = $oldDbOverride }
    $exists = & $MysqlExe @mysqlBase --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$testDatabase';" 2>$null
    if ($LASTEXITCODE -eq 0 -and [int]$exists -eq 1) { & $MysqlExe @mysqlBase -e "DROP DATABASE $testDatabase;" | Out-Null }
    foreach ($path in @($dumpFile, $serverOut, $serverErr)) { if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue } }
    if (Test-Path -LiteralPath $sessionDir) {
        $tempRoot = (Resolve-Path -LiteralPath $env:TEMP).Path.TrimEnd('\') + '\'
        $sessionFullPath = (Resolve-Path -LiteralPath $sessionDir).Path
        if ($sessionFullPath.StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $sessionFullPath).StartsWith('sbb-php-session-')) {
            Remove-Item -LiteralPath $sessionFullPath -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}
