using System.Globalization;
using System.Text;

static class DemoReceiptPdf
{
    public static byte[] Create()
    {
        string width = (80 * 72 / 25.4).ToString("0.####", CultureInfo.InvariantCulture);
        string height = (90 * 72 / 25.4).ToString("0.####", CultureInfo.InvariantCulture);
        string content = "BT /F1 12 Tf 12 225 Td (OSONPOS TEST - 1.0.1) Tj "
            + "0 -24 Td /F1 10 Tf (80 mm receipt / printer check) Tj "
            + "0 -20 Td (0123456789 - ABCDEFGHIJ) Tj "
            + "0 -20 Td (NO SALE / STOCK / DEBT CREATED) Tj "
            + "0 -20 Td (Please check all text is visible.) Tj ET\n";
        string[] objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            $"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {width} {height}] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
            $"<< /Length {Encoding.ASCII.GetByteCount(content)} >>\nstream\n{content}endstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"
        ];
        var pdf = new StringBuilder("%PDF-1.4\n");
        var offsets = new List<int> { 0 };
        for (int i = 0; i < objects.Length; i++)
        {
            offsets.Add(Encoding.ASCII.GetByteCount(pdf.ToString()));
            pdf.Append($"{i + 1} 0 obj\n{objects[i]}\nendobj\n");
        }
        int crossReference = Encoding.ASCII.GetByteCount(pdf.ToString());
        pdf.Append($"xref\n0 {offsets.Count}\n0000000000 65535 f \n");
        foreach (int offset in offsets.Skip(1)) pdf.Append(offset.ToString("D10", CultureInfo.InvariantCulture) + " 00000 n \n");
        pdf.Append($"trailer\n<< /Size {offsets.Count} /Root 1 0 R >>\nstartxref\n{crossReference}\n%%EOF\n");
        return Encoding.ASCII.GetBytes(pdf.ToString());
    }
}
