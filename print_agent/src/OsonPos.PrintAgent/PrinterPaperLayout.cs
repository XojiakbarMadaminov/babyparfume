using System.Drawing.Printing;
using System.Globalization;
using System.Text.RegularExpressions;

namespace OsonPos.PrintAgent;

public sealed record PrinterPageMetrics(int DpiX, int DpiY, int PhysicalWidth, int PhysicalHeight,
    int PrintableWidth, int PrintableHeight, int OffsetX, int OffsetY)
{
    public double PhysicalWidthMm => PhysicalWidth * 25.4 / DpiX;
    public double PhysicalHeightMm => PhysicalHeight * 25.4 / DpiY;
    public double PrintableWidthMm => PrintableWidth * 25.4 / DpiX;
    public double PrintableHeightMm => PrintableHeight * 25.4 / DpiY;
}

public sealed record PrinterPaperLayout(double Scale, Rectangle Bounds)
{
    public static PaperSize? ReceiptForm(double rollWidth, PaperSize current, IEnumerable<PaperSize> forms)
    {
        bool Matches(PaperSize form)
        {
            var match = Regex.Match(form.PaperName, @"^\s*(\d+(?:[.,]\d+)?)\s*\(");
            if (match.Success)
                return double.TryParse(match.Groups[1].Value.Replace(',', '.'), CultureInfo.InvariantCulture, out var nominal)
                    && Math.Abs(nominal - rollWidth) <= .6 && form.Width * .254 <= rollWidth + .6;
            return Math.Abs(form.Width * .254 - rollWidth) <= .6;
        }
        return Matches(current) ? current : forms.Where(Matches).OrderBy(f => Math.Abs(f.Width * .254 - rollWidth)).FirstOrDefault();
    }

    public static PrinterPaperLayout Create(string type, double documentWidth, double documentHeight,
        double requestedDriverWidth, PrinterPageMetrics metrics)
    {
        if (!double.IsFinite(documentWidth) || !double.IsFinite(documentHeight) || documentWidth <= 0 || documentHeight <= 0
            || metrics.DpiX <= 0 || metrics.DpiY <= 0 || metrics.PrintableWidth <= 0 || metrics.PrintableHeight <= 0)
            throw new InvalidDataException("paper_size_unsupported: Printer o‘lchamlari yaroqsiz.");

        bool receipt = type == "receipt";
        bool widthMatches = Math.Abs(metrics.PhysicalWidthMm - requestedDriverWidth) <= 1;
        bool heightMatches = Math.Abs(metrics.PhysicalHeightMm - documentHeight) <= 1
            || (receipt && Math.Abs(metrics.PrintableHeightMm - documentHeight) <= 1);
        if (!widthMatches || !heightMatches)
            throw new InvalidDataException(FormattableString.Invariant($"paper_size_unsupported: Talab {documentWidth:0.#}×{documentHeight:0.#} mm; drayver sahifasi {metrics.PhysicalWidthMm:0.#}×{metrics.PhysicalHeightMm:0.#} mm, bosiladigan joy {metrics.PrintableWidthMm:0.#}×{metrics.PrintableHeightMm:0.#} mm. Qog‘oz profilini tekshiring."));

        double scale = receipt ? Math.Min(1, Math.Min(metrics.PrintableWidthMm / documentWidth, metrics.PrintableHeightMm / documentHeight)) : 1;
        int width = (int)Math.Round(documentWidth * metrics.DpiX / 25.4 * scale);
        int height = (int)Math.Round(documentHeight * metrics.DpiY / 25.4 * scale);
        var bounds = receipt ? new Rectangle(Math.Max(0, (metrics.PrintableWidth - width) / 2), 0, width, height)
            : new Rectangle(-metrics.OffsetX, -metrics.OffsetY, width, height);
        return new(scale, bounds);
    }
}
