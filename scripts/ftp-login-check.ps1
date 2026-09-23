# Local FTP login check.
#
# Asks for the password interactively (never stored, never printed), then tries a plain FTP
# login to the host and lists the directory. This separates two very different problems:
#
#   - login SUCCEEDS here but GitHub still says 530  -> the GitHub secret is wrong/stale
#   - login FAILS here too                            -> the FTP account or password is wrong
#
# Run:  powershell -NoProfile -ExecutionPolicy Bypass -File ftp-login-check.ps1

$server = 'ftp.mwstay.com'
$username = 'darscoffeeeftipi@darscoffee.com'

Write-Host ''
Write-Host "Server   : $server" -ForegroundColor Cyan
Write-Host "Username : $username" -ForegroundColor Cyan
Write-Host ''

$secure = Read-Host -Prompt 'FTP password (input hidden)' -AsSecureString
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $password = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)

    # ---- 1. Does the password authenticate? ----
    Write-Host ''
    Write-Host '--- login test (plain FTP, port 21) ---' -ForegroundColor Yellow

    $req = [System.Net.FtpWebRequest]::Create("ftp://$server/")
    $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $req.Credentials = New-Object System.Net.NetworkCredential($username, $password)
    $req.UsePassive = $true
    $req.UseBinary = $true
    $req.EnableSsl = $false          # plain FTP, matching the workflow
    $req.KeepAlive = $false
    $req.Timeout = 30000

    try {
        $resp = $req.GetResponse()
        $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $listing = $sr.ReadToEnd()
        $sr.Close(); $resp.Close()

        Write-Host 'LOGIN OK' -ForegroundColor Green
        Write-Host ''
        Write-Host '--- directory listing (this is where FTP lands) ---' -ForegroundColor Yellow
        foreach ($line in ($listing -split "`n")) {
            if ($line.Trim()) { Write-Host ('  ' + $line.Trim()) }
        }
        Write-Host ''
        Write-Host 'Look for: artisan, app/, public/, vendor/, .env' -ForegroundColor Cyan
        Write-Host 'If you see a single subfolder instead, that is the nesting problem.' -ForegroundColor Cyan
    }
    catch {
        $we = $_.Exception
        Write-Host 'LOGIN FAILED' -ForegroundColor Red
        Write-Host ('  message: ' + $we.Message)
        if ($we.Response -is [System.Net.FtpWebResponse]) {
            Write-Host ('  FTP status: ' + [int]$we.Response.StatusCode + ' ' + $we.Response.StatusDescription)
        }
        if ($we -is [System.Net.WebException]) {
            Write-Host ('  WebException status: ' + $we.Status)
        }
    }
}
finally {
    # Wipe the plaintext password from memory.
    [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    $password = $null
}

Write-Host ''
Write-Host 'Done. Nothing above contains your password.' -ForegroundColor Cyan
