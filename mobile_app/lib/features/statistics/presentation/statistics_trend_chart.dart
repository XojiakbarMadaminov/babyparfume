import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../data/statistics_repository.dart';
import 'statistics_widgets.dart';

class StatisticsTrendChart extends StatefulWidget {
  final List<StatisticsPoint> points;
  const StatisticsTrendChart({super.key, required this.points});
  @override
  State<StatisticsTrendChart> createState() => _StatisticsTrendChartState();
}

class _StatisticsTrendChartState extends State<StatisticsTrendChart> {
  final _focus = FocusNode();
  int? _selected;
  bool _showValues = false;
  @override
  void didUpdateWidget(covariant StatisticsTrendChart oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.points != widget.points) {
      _selected = null;
      _showValues = false;
    }
  }

  @override
  void dispose() {
    _focus.dispose();
    super.dispose();
  }

  void _move(int delta) {
    if (widget.points.isEmpty) return;
    setState(() => _selected =
        ((_selected ?? -1) + delta).clamp(0, widget.points.length - 1));
  }

  void _select(double x, double width) {
    if (widget.points.isEmpty) return;
    _focus.requestFocus();
    setState(() => _selected =
        ((x - 12) / math.max(1, width - 24) * (widget.points.length - 1))
            .round()
            .clamp(0, widget.points.length - 1));
  }

  KeyEventResult _key(FocusNode node, KeyEvent event) {
    if (event is! KeyDownEvent || widget.points.isEmpty) {
      return KeyEventResult.ignored;
    }
    if (event.logicalKey == LogicalKeyboardKey.arrowRight) {
      _move(1);
    } else if (event.logicalKey == LogicalKeyboardKey.arrowLeft) {
      _move(-1);
    } else if (event.logicalKey == LogicalKeyboardKey.home) {
      setState(() => _selected = 0);
    } else if (event.logicalKey == LogicalKeyboardKey.end) {
      setState(() => _selected = widget.points.length - 1);
    } else if (event.logicalKey == LogicalKeyboardKey.escape) {
      setState(() => _selected = null);
    } else {
      return KeyEventResult.ignored;
    }
    return KeyEventResult.handled;
  }

  @override
  Widget build(BuildContext context) {
    final active = _selected == null ? null : widget.points[_selected!];
    String pointDescription(int index) {
      final point = widget.points[index.clamp(0, widget.points.length - 1)];
      return '${point.label}: ${StatisticsStyle.money(point.amount)}';
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 43),
          child: Semantics(
              liveRegion: true,
              child: active == null
                  ? Text(
                      'Nuqtani bosing yoki yo‘nalish tugmalaridan foydalaning.',
                      style: TextStyle(
                          fontSize: 12,
                          color: StatisticsStyle.colors(context).muted,
                          height: 1.5))
                  : Wrap(
                      alignment: WrapAlignment.spaceBetween,
                      spacing: 8,
                      runSpacing: 4,
                      children: [
                          Text(active.label,
                              style: TextStyle(
                                  fontSize: 12,
                                  color:
                                      StatisticsStyle.colors(context).muted)),
                          Text(StatisticsStyle.money(active.amount),
                              key: const ValueKey('trend-readout'),
                              style: const TextStyle(
                                  fontSize: 14, fontWeight: FontWeight.w600)),
                        ]))),
      LayoutBuilder(
          builder: (context, constraints) => Focus(
              focusNode: _focus,
              onKeyEvent: _key,
              child: Semantics(
                  label: 'Sotuv dinamikasi',
                  value: active == null
                      ? 'Nuqtani tanlang'
                      : '${active.label}: ${StatisticsStyle.money(active.amount)}',
                  increasedValue: widget.points.isEmpty
                      ? null
                      : pointDescription((_selected ?? -1) + 1),
                  decreasedValue: widget.points.isEmpty
                      ? null
                      : pointDescription((_selected ?? 1) - 1),
                  onIncrease: widget.points.isEmpty ? null : () => _move(1),
                  onDecrease: widget.points.isEmpty ? null : () => _move(-1),
                  child: GestureDetector(
                      key: const ValueKey('statistics-trend'),
                      onTapDown: (details) => _select(
                          details.localPosition.dx, constraints.maxWidth),
                      onHorizontalDragUpdate: (details) => _select(
                          details.localPosition.dx, constraints.maxWidth),
                      child: AspectRatio(
                          aspectRatio: 320 / 148,
                          child: CustomPaint(
                              painter: StatisticsTrendPainter(
                                  points: widget.points,
                                  colors: StatisticsStyle.colors(context),
                                  selected: _selected))))))),
      const SizedBox(height: 8),
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        if (widget.points.isNotEmpty)
          Flexible(
              child: Text(widget.points.first.label,
                  style: TextStyle(
                      fontSize: 11,
                      color: StatisticsStyle.colors(context).muted))),
        if (widget.points.length > 2)
          Flexible(
              child: Text(widget.points[(widget.points.length - 1) ~/ 2].label,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 11,
                      color: StatisticsStyle.colors(context).muted))),
        if (widget.points.length > 1)
          Flexible(
              child: Text(widget.points.last.label,
                  textAlign: TextAlign.right,
                  style: TextStyle(
                      fontSize: 11,
                      color: StatisticsStyle.colors(context).muted))),
      ]),
      const SizedBox(height: 12),
      TextButton(
          onPressed: () => setState(() => _showValues = !_showValues),
          style: TextButton.styleFrom(
              alignment: Alignment.centerLeft,
              minimumSize: const Size(0, 44),
              padding: EdgeInsets.zero),
          child: Row(children: [
            Icon(_showValues ? Icons.expand_less : Icons.expand_more, size: 18),
            const SizedBox(width: 5),
            const Flexible(child: Text('Qiymatlarni ro‘yxatda ko‘rish'))
          ])),
      if (_showValues) ...[
        for (final point in widget.points)
          Container(
              padding: const EdgeInsets.symmetric(vertical: 8),
              decoration: BoxDecoration(
                  border: Border(
                      top: BorderSide(
                          color: StatisticsStyle.colors(context).line))),
              child: Row(children: [
                Expanded(
                    child: Text(point.label,
                        style: TextStyle(
                            fontSize: 12,
                            color: StatisticsStyle.colors(context).muted))),
                Text(StatisticsStyle.money(point.amount),
                    style: const TextStyle(fontSize: 12))
              ])),
      ]
    ]);
  }
}

class StatisticsTrendPainter extends CustomPainter {
  final List<StatisticsPoint> points;
  final int? selected;
  final StatisticsPalette colors;
  const StatisticsTrendPainter(
      {required this.points, this.selected, required this.colors});

  @override
  void paint(Canvas canvas, Size size) {
    const inset = 12.0;
    final grid = Paint()
      ..color = colors.line
      ..strokeWidth = 1;
    for (final y in [inset, size.height / 2, size.height - inset]) {
      for (var x = inset; x < size.width - inset; x += 8) {
        canvas.drawLine(
            Offset(x, y), Offset(math.min(x + 3, size.width - inset), y), grid);
      }
    }
    if (points.isEmpty) return;
    final minimum =
        points.fold<double>(0, (value, point) => math.min(value, point.amount));
    final maximum =
        points.fold<double>(1, (value, point) => math.max(value, point.amount));
    final offsets = [
      for (var index = 0; index < points.length; index++)
        Offset(
            points.length == 1
                ? size.width / 2
                : inset +
                    index * (size.width - inset * 2) / (points.length - 1),
            size.height -
                inset -
                (points[index].amount - minimum) /
                    (maximum - minimum) *
                    (size.height - inset * 2))
    ];
    final line = Path()..moveTo(offsets.first.dx, offsets.first.dy);
    for (final point in offsets.skip(1)) {
      line.lineTo(point.dx, point.dy);
    }
    final area = Path.from(line)
      ..lineTo(offsets.last.dx, size.height - inset)
      ..lineTo(offsets.first.dx, size.height - inset)
      ..close();
    canvas.drawPath(
        area,
        Paint()
          ..shader = LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                colors.accent.withOpacity(.22),
                colors.accent.withOpacity(0)
              ]).createShader(Offset.zero & size));
    canvas.drawPath(
        line,
        Paint()
          ..color = colors.accent
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.5
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round);
    if (points.length == 1) {
      canvas.drawCircle(offsets.first, 4, Paint()..color = colors.accent);
    }
    if (selected != null && selected! < offsets.length) {
      final point = offsets[selected!];
      for (var y = inset; y < size.height - inset; y += 7) {
        canvas.drawLine(
            Offset(point.dx, y),
            Offset(point.dx, math.min(y + 3, size.height - inset)),
            Paint()
              ..color = colors.accent.withOpacity(.5)
              ..strokeWidth = 1);
      }
      canvas.drawCircle(point, 5, Paint()..color = colors.accent);
    }
  }

  @override
  bool shouldRepaint(covariant StatisticsTrendPainter oldDelegate) =>
      oldDelegate.points != points ||
      oldDelegate.selected != selected ||
      oldDelegate.colors.isDark != colors.isDark;
}
