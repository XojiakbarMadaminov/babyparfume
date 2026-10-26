namespace OsonPos.PrintAgent;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        if (args.Length == 6 && args[0] == "--check-printer")
        {
            AgentDiagnostics.CheckPrinter(args[1], args[2], double.Parse(args[3], System.Globalization.CultureInfo.InvariantCulture), double.Parse(args[4], System.Globalization.CultureInfo.InvariantCulture), args[5]);
            return;
        }
        if (args.Length == 3 && args[0] == "--check-pdf") { AgentDiagnostics.CheckPdf(args[1], args[2]).GetAwaiter().GetResult(); return; }
        if (args.Contains("--install") || Path.GetFileNameWithoutExtension(Environment.ProcessPath!).EndsWith("Setup", StringComparison.OrdinalIgnoreCase))
        { AgentInstallation.Install(); return; }
        if (args.Contains("--uninstall")) { AgentInstallation.Uninstall(false, 0); return; }
        if (args.Length == 2 && args[0] == "--remove-install" && int.TryParse(args[1], out int pid)) { AgentInstallation.Uninstall(true, pid); return; }
        using var mutex = new Mutex(true, @"Local\OsonPOS.PrintAgent", out bool created);
        if (!created) { MessageBox.Show("OsonPOS agent allaqachon ishlab turibdi. Tray belgisidan oching."); return; }
        try { Application.Run(new MainForm(args.Contains("--background"))); }
        finally { mutex.ReleaseMutex(); }
    }
}
