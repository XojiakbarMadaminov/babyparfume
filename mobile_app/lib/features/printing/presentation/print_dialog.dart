import 'dart:async';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:printing/printing.dart';
import 'package:uuid/uuid.dart';
import '../../products/data/products_repository.dart';
import '../data/printing_repository.dart';

class PrintDialog extends StatefulWidget {
  final String storeId;
  final String? receiptId;
  final List<LocalProduct> products;
  final Map<String, dynamic>? existingJob;
  final PrintingRepository? repository;
  final Widget Function(Uint8List)? previewBuilder;
  const PrintDialog(
      {super.key,
      required this.storeId,
      this.receiptId,
      this.products = const [],
      this.existingJob,
      this.repository,
      this.previewBuilder});
  @override
  State<PrintDialog> createState() => _PrintDialogState();
}

class _PrintDialogState extends State<PrintDialog> {
  late final PrintingRepository _repo;
  final _form = GlobalKey<FormState>();
  final _copies = TextEditingController(text: '1');
  final _quantities = <String, TextEditingController>{};
  List<Map<String, dynamic>> _profiles = [];
  Map<String, dynamic>? _source;
  Map<String, dynamic>? _preview;
  Map<String, dynamic>? _job;
  Uint8List? _pdf;
  String? _profileId;
  String? _intentId;
  String? _error;
  bool _loading = true;
  bool _busy = false;
  bool _online = false;
  bool _canReset = false;
  bool _canReprintReceipt = false;
  Timer? _timer;
  bool get _receipt =>
      widget.receiptId != null ||
      widget.existingJob?['document_type'] == 'receipt' ||
      _job?['document_type'] == 'receipt';
  Map<String, dynamic>? get _profile =>
      _profiles.where((p) => p['id'] == _profileId).firstOrNull;
  bool get _locked => _intentId != null;

  @override
  void initState() {
    super.initState();
    _repo = widget.repository ?? PrintingRepository();
    for (final p in widget.products) {
      _quantities[p.id] = TextEditingController(text: '1');
    }
    _job = widget.existingJob;
    _load();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _copies.dispose();
    for (final c in _quantities.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load({bool fresh = false}) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      if (_receipt) {
        try {
          _canReprintReceipt =
              (await _repo.options(widget.storeId))['can_reprint_receipts'] ==
                  true;
        } catch (_) {}
      }
      if (_job != null) {
        _watch();
        setState(() => _loading = false);
        return;
      }
      if (_receipt && !fresh) {
        final saved = await _repo.receiptIntent(widget.receiptId!);
        if (saved != null) {
          _intentId = (saved['payload'] as Map)['client_job_id'].toString();
          _canReset = ['expired', 'rejected'].contains(saved['state']);
          _job = saved['result'] is Map
              ? Map<String, dynamic>.from(saved['result'] as Map)
              : null;
          if (mounted) setState(() => _loading = false);
          if (_job != null) _watch();
          return;
        }
      }
      final data = await _repo.options(widget.storeId);
      _online = data['agent_online'] == true;
      _profiles = (data['profiles'] as List)
          .map((p) => Map<String, dynamic>.from(p as Map))
          .where((p) =>
              p['document_type'] == (_receipt ? 'receipt' : 'barcode') &&
              p['selectable'] == true)
          .toList();
      if (_profiles.isNotEmpty) {
        _profileId =
            (_profiles.where((p) => p['is_default'] == true).firstOrNull ??
                    _profiles.first)['id']
                .toString();
        _copies.text = _profile?['default_copies'].toString() ?? '1';
      }
      if (_receipt) _source = await _repo.receiptSource(widget.receiptId!);
      if (mounted) setState(() => _loading = false);
    } catch (e) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = PrintingRepository.error(e);
        });
      }
    }
  }

  void _changed() {
    setState(() {
      _preview = null;
      _pdf = null;
      _error = null;
    });
  }

  Map<String, dynamic> _documentPayload() {
    final p = _profile!;
    return {
      'document_type': _receipt ? 'receipt' : 'barcode',
      'paper_profile_id': p['id'],
      'paper_profile_version': p['version'],
      if (_receipt) ...{'source': _source, 'copies': int.parse(_copies.text)},
      if (!_receipt)
        'items': widget.products
            .map((item) => {
                  'product_id': item.id,
                  'copies': int.parse(_quantities[item.id]!.text)
                })
            .toList(),
    };
  }

  Future<void> _makePreview() async {
    if (_busy ||
        _profile == null ||
        !(_form.currentState?.validate() ?? false)) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final preview = await _repo.preview(widget.storeId, _documentPayload());
      final bytes = await _repo.pdf(preview['document_path'].toString());
      if (mounted) {
        setState(() {
          _preview = preview;
          _pdf = bytes;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = PrintingRepository.error(e);
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    if (_busy ||
        _locked ||
        _profile == null ||
        !(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      _preview ??= await _repo.preview(widget.storeId, _documentPayload());
      _intentId ??= const Uuid().v4();
      final job = await _repo.submit(
          widget.storeId,
          {
            'client_job_id': _intentId,
            'document_token': _preview!['document_token']
          },
          receiptId: widget.receiptId);
      if (mounted) setState(() => _job = job);
      _watch();
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = PrintingRepository.error(e);
          _canReset = PrintingRepository.isRejected(e);
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _refresh() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final job = _job != null
          ? await _repo.status(widget.storeId, _job!['id'].toString())
          : await _repo.retry(_intentId!);
      if (mounted) setState(() => _job = job);
      _watch();
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = PrintingRepository.error(e);
          _canReset = PrintingRepository.isRejected(e) ||
              _error!.contains('Yangi tasdiq kerak');
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _watch() {
    if (!mounted) return;
    _timer?.cancel();
    if (_job != null &&
        [
          'queued',
          'claimed',
          'rendering',
          'submitting',
          'failed_before_submission'
        ].contains(_job!['status'])) {
      _timer = Timer(const Duration(seconds: 4), () {
        if (mounted) _refresh();
      });
    }
  }

  Future<void> _cancel() async {
    setState(() => _busy = true);
    try {
      await _repo.cancel(widget.storeId, _job!['id'].toString());
      _job = await _repo.status(widget.storeId, _job!['id'].toString());
    } catch (e) {
      _error = PrintingRepository.error(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reprint() async {
    if (_busy) return;
    String reason = '';
    final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
                title: const Text('Qayta chop etish'),
                content: Column(mainAxisSize: MainAxisSize.min, children: [
                  const Text(
                      'Avvalgi nusxa chiqqanini printerdan tekshiring. Yangi nusxa chiqarish sababini yozing.'),
                  const SizedBox(height: 12),
                  TextFormField(
                      maxLength: 255,
                      onChanged: (v) => reason = v,
                      decoration: const InputDecoration(labelText: 'Sabab')),
                ]),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('Bekor qilish')),
                  TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('Tasdiqlash'))
                ]));
    if (confirmed != true || reason.trim().isEmpty || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final id = const Uuid().v4();
    final parent = _job!['id'].toString();
    _intentId = id;
    setState(() => _job = null);
    try {
      final job = await _repo.submit(
          widget.storeId, {'client_job_id': id, 'reason': reason.trim()},
          receiptId: widget.receiptId, reprintOf: parent);
      if (mounted) setState(() => _job = job);
      _watch();
    } catch (e) {
      if (mounted) setState(() => _error = PrintingRepository.error(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _validateCount(String? text, int max) {
    final value = int.tryParse(text ?? '');
    return value == null || value < 1 || value > max
        ? '1 dan $max gacha butun son kiriting.'
        : null;
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final status = _job?['status']?.toString();
    final child = PopScope(
        canPop: !_busy,
        child: Scaffold(
          appBar: AppBar(
              automaticallyImplyLeading: false,
              title:
                  Text(_receipt ? 'Chekni chop etish' : 'Barcode chop etish'),
              leading: IconButton(
                  tooltip: 'Yopish',
                  onPressed: _busy ? null : () => Navigator.pop(context),
                  icon: const Icon(Icons.close))),
          body: _loading
              ? const Center(child: CircularProgressIndicator())
              : ListView(padding: const EdgeInsets.all(16), children: [
                  if (_error != null) ...[
                    Text(_error!, style: TextStyle(color: colors.error)),
                    const SizedBox(height: 16)
                  ],
                  if (_job != null) ...[
                    Icon(
                        status == 'unknown'
                            ? Icons.warning_amber
                            : Icons.print_outlined,
                        size: 48,
                        color: colors.primary),
                    const SizedBox(height: 16),
                    Text(PrintingRepository.statusLabel(status!),
                        style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 12),
                    Text('${_job!['profile_name']} · ${_job!['printer_name']}'),
                    Text(
                        'Nusxalar / etiketkalar: ${_job!['document_type'] == 'barcode' ? _job!['total_labels'] : _job!['copies']}'),
                    if (_job!['message'] != null)
                      Text(_job!['message'].toString()),
                    if (_job!['error_code'] != null)
                      Text('Holat: ${_job!['error_code']}'),
                    const SizedBox(height: 16),
                    OutlinedButton(
                        onPressed: _busy ? null : _refresh,
                        child: const Text('Holatni tekshirish')),
                    if ([
                      'queued',
                      'claimed',
                      'rendering',
                      'failed_before_submission'
                    ].contains(status))
                      TextButton(
                          onPressed: _busy ? null : _cancel,
                          child: const Text('Topshiriqni bekor qilish')),
                    if ((!_receipt || _canReprintReceipt) &&
                        [
                          'spooled',
                          'completed_reported',
                          'unknown',
                          'expired',
                          'cancelled',
                          'failed_before_submission'
                        ].contains(status))
                      OutlinedButton(
                          onPressed: _busy ? null : _reprint,
                          child: const Text('Qayta chop etish')),
                  ] else if (_locked) ...[
                    const Text(
                        'So‘rov qurilmada saqlangan. Yangi nusxa yuborishdan oldin serverdagi holatini tekshiring.'),
                    const SizedBox(height: 16),
                    ElevatedButton(
                        onPressed: _busy ? null : _refresh,
                        child: const Text('Qayta tekshirish')),
                    if (_canReset &&
                        (widget.receiptId != null ||
                            widget.products.isNotEmpty))
                      TextButton(
                          onPressed: () {
                            _intentId = null;
                            _preview = null;
                            _pdf = null;
                            _canReset = false;
                            _load(fresh: true);
                          },
                          child: const Text('Yangi tasdiq bilan tayyorlash')),
                  ] else if (_profiles.isEmpty) ...[
                    const Text(
                        'Admin faol printer va qog‘oz profilini biriktirishi kerak.'),
                    TextButton(
                        onPressed: () => _load(fresh: true),
                        child: const Text('Yangilash')),
                  ] else
                    Form(
                        key: _form,
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              if (!_online)
                                Padding(
                                    padding: const EdgeInsets.only(bottom: 16),
                                    child: Text(
                                        'Kompyuter offline. So‘rov yuborilgach ko‘pi bilan 10 daqiqa kutiladi.',
                                        style: TextStyle(
                                            color: colors.onSurfaceVariant))),
                              DropdownButtonFormField<String>(
                                  value: _profileId,
                                  isExpanded: true,
                                  decoration: const InputDecoration(
                                      labelText: 'Qog‘oz / etiketka o‘lchami'),
                                  items: _profiles
                                      .map((p) => DropdownMenuItem(
                                          value: p['id'].toString(),
                                          child: Text(p['name'].toString(),
                                              overflow: TextOverflow.ellipsis)))
                                      .toList(),
                                  onChanged: _busy
                                      ? null
                                      : (v) {
                                          _profileId = v;
                                          _copies.text =
                                              _profile?['default_copies']
                                                      .toString() ??
                                                  '1';
                                          _changed();
                                        }),
                              const SizedBox(height: 12),
                              Text(
                                  'Printer: ${_profile?['printer_name'] ?? ''}'),
                              const SizedBox(height: 16),
                              TextButton(
                                  onPressed: _busy
                                      ? null
                                      : () {
                                          _changed();
                                          _load(fresh: true);
                                        },
                                  child: const Text('Sozlamalarni yangilash')),
                              if (_receipt)
                                TextFormField(
                                    controller: _copies,
                                    readOnly: _busy,
                                    keyboardType: TextInputType.number,
                                    decoration: const InputDecoration(
                                        labelText: 'Nusxalar soni'),
                                    onChanged: (_) => _changed(),
                                    validator: (v) => _validateCount(
                                        v,
                                        (_profile?['max_copies'] as num?)
                                                ?.toInt() ??
                                            1)),
                              if (!_receipt)
                                for (final item in widget.products)
                                  Padding(
                                      padding:
                                          const EdgeInsets.only(bottom: 16),
                                      child: TextFormField(
                                          controller: _quantities[item.id],
                                          readOnly: _busy,
                                          keyboardType: TextInputType.number,
                                          decoration: InputDecoration(
                                              labelText: item.name,
                                              helperText: item.barcode,
                                              suffixText: 'etiketka'),
                                          onChanged: (_) => _changed(),
                                          validator: (v) => _validateCount(
                                              v,
                                              (_profile?['max_copies'] as num?)
                                                      ?.toInt() ??
                                                  1))),
                              if (_preview != null && _pdf != null) ...[
                                const SizedBox(height: 16),
                                Text(
                                    'Tayyor: ${_receipt ? _preview!['copies'] : _preview!['total_labels']} ta'),
                                const SizedBox(height: 12),
                                SizedBox(
                                    height: 360,
                                    child: widget.previewBuilder?.call(_pdf!) ??
                                        PdfPreview(
                                            build: (_) => Future.value(_pdf!),
                                            allowPrinting: false,
                                            allowSharing: false,
                                            canChangePageFormat: false,
                                            canChangeOrientation: false,
                                            canDebug: false,
                                            useActions: false)),
                              ],
                            ])),
                ]),
          bottomNavigationBar: SafeArea(
              top: false,
              child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: !_loading &&
                          !_locked &&
                          _job == null &&
                          _profiles.isNotEmpty
                      ? SizedBox(
                          height: 56,
                          child: Row(children: [
                            Expanded(
                                flex: 3,
                                child: OutlinedButton.icon(
                                    onPressed: _busy ? null : _makePreview,
                                    icon: const Icon(Icons.preview_outlined,
                                        size: 20),
                                    label: const Text('Oldindan ko‘rish',
                                        textAlign: TextAlign.center))),
                            const SizedBox(width: 12),
                            Expanded(
                                flex: 2,
                                child: FilledButton.icon(
                                    onPressed: _busy ? null : _submit,
                                    icon: const Icon(Icons.print, size: 20),
                                    label: const Text('Print'))),
                          ]))
                      : const SizedBox.shrink())),
        ));
    if (MediaQuery.sizeOf(context).width < 600) {
      return Dialog.fullscreen(child: child);
    }
    return Dialog(
        clipBehavior: Clip.antiAlias,
        child: SizedBox(
            width: 640,
            height: MediaQuery.sizeOf(context).height * .9,
            child: child));
  }
}

class PrintHistoryScreen extends StatefulWidget {
  final String storeId;
  final PrintingRepository? repository;
  const PrintHistoryScreen({super.key, required this.storeId, this.repository});
  @override
  State<PrintHistoryScreen> createState() => _PrintHistoryScreenState();
}

class _PrintHistoryScreenState extends State<PrintHistoryScreen> {
  late final PrintingRepository _repo;
  List<Map<String, dynamic>>? _jobs;
  String? _error;
  @override
  void initState() {
    super.initState();
    _repo = widget.repository ?? PrintingRepository();
    _load();
  }

  Future<void> _load() async {
    try {
      final jobs = await _repo.history(widget.storeId);
      if (mounted) {
        setState(() {
          _jobs = jobs;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = PrintingRepository.error(e));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('Chop etish tarixi'), actions: [
        IconButton(onPressed: _load, icon: const Icon(Icons.refresh))
      ]),
      body: _error != null
          ? Center(
              child: Padding(
                  padding: const EdgeInsets.all(24), child: Text(_error!)))
          : _jobs == null
              ? const Center(child: CircularProgressIndicator())
              : _jobs!.isEmpty
                  ? const Center(child: Text('Chop etish topshiriqlari yo‘q.'))
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                          itemCount: _jobs!.length,
                          itemBuilder: (context, i) {
                            final job = _jobs![i];
                            return ListTile(
                                leading: const Icon(Icons.print_outlined),
                                title: Text(
                                    '${job['profile_name']} · ${job['printer_name']}'),
                                subtitle: Text(PrintingRepository.statusLabel(
                                    job['status'].toString())),
                                onTap: () async {
                                  await showDialog<void>(
                                      context: context,
                                      barrierDismissible: false,
                                      builder: (_) => PrintDialog(
                                          storeId: widget.storeId,
                                          existingJob: job,
                                          repository: _repo));
                                  _load();
                                });
                          })));
}
