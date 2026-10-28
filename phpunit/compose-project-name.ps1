function Get-MoodlePhpUnitComposeProjectName {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$RepositoryRoot
    )

    $repositoryPath = [System.IO.Path]::GetFullPath($RepositoryRoot).TrimEnd([char[]]@('\', '/'))
    if ([System.IO.Path]::DirectorySeparatorChar -eq [char]92) {
        $repositoryPath = $repositoryPath.ToLowerInvariant()
    }

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $pathBytes = [System.Text.Encoding]::UTF8.GetBytes($repositoryPath)
        $digest = $sha256.ComputeHash($pathBytes)
    } finally {
        $sha256.Dispose()
    }

    $suffix = [System.BitConverter]::ToString($digest).Replace('-', '').Substring(0, 12).ToLowerInvariant()
    return "rtc-moodle-phpunit-$suffix"
}
