[Setup]
AppName=eBAL Smart Bridge
AppVersion=1.0.0
DefaultDirName={pf}\eBAL Tally Bridge
DefaultGroupName=eBAL Tally Bridge
OutputDir=dist
OutputBaseFilename=ebal-smart-bridge-setup
Compression=lzma
SolidCompression=yes

[Files]
Source: "dist\ebal_smart_bridge.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "bundle\cloudflared.exe"; DestDir: "{app}"; Flags: ignoreversion; Check: FileExists(ExpandConstant('{src}\bundle\cloudflared.exe'))

[Icons]
Name: "{group}\eBAL Smart Bridge"; Filename: "{app}\ebal_smart_bridge.exe"
Name: "{commondesktop}\eBAL Smart Bridge"; Filename: "{app}\ebal_smart_bridge.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop icon"; GroupDescription: "Additional icons:"

[Run]
Filename: "{app}\ebal_smart_bridge.exe"; Description: "Launch eBAL Smart Bridge"; Flags: nowait postinstall skipifsilent
