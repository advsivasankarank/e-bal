[Setup]
AppName=eBAL Tally Bridge
AppVersion=1.0.0
DefaultDirName={pf}\eBAL Tally Bridge
DefaultGroupName=eBAL Tally Bridge
OutputDir=dist
OutputBaseFilename=ebal-tally-bridge-setup
Compression=lzma
SolidCompression=yes

[Files]
Source: "dist\ebal-tally-bridge.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "bundle\ngrok.exe"; DestDir: "{app}"; Flags: ignoreversion; Check: FileExists(ExpandConstant('{src}\bundle\ngrok.exe'))
Source: "bundle\cloudflared.exe"; DestDir: "{app}"; Flags: ignoreversion; Check: FileExists(ExpandConstant('{src}\bundle\cloudflared.exe'))

[Icons]
Name: "{group}\eBAL Tally Bridge"; Filename: "{app}\ebal-tally-bridge.exe"
Name: "{commondesktop}\eBAL Tally Bridge"; Filename: "{app}\ebal-tally-bridge.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop icon"; GroupDescription: "Additional icons:"

[Run]
Filename: "{app}\ebal-tally-bridge.exe"; Description: "Launch eBAL Tally Bridge"; Flags: nowait postinstall skipifsilent
