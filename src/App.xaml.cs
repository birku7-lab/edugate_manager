using System;
using System.Windows;
using SoftBuilt.PHPShield.Services;

namespace SoftBuilt.PHPShield
{
    public partial class App : Application
    {
        protected override void OnStartup(StartupEventArgs e)
        {
            ShutdownMode = ShutdownMode.OnMainWindowClose;

            try
            {
                var mainWindow = new MainWindow();
                MainWindow = mainWindow;
                mainWindow.Show();
            }
            catch (Exception ex)
            {
                AuditLogService.Instance.Log("SYSTEM", "Startup Failure", ex.ToString(), false);
                MessageBox.Show("Startup error: " + ex.Message + "\n\nStack Trace:\n" + ex.StackTrace,
                    "SoftBuilt PHP Shield Error", MessageBoxButton.OK, MessageBoxImage.Error);
                Shutdown(1);
            }
        }

        protected override void OnExit(ExitEventArgs e)
        {
            ServiceMonitorService.Instance.HandleApplicationExit();
            ServiceMonitorService.Instance.Dispose();
            base.OnExit(e);
        }
    }
}
