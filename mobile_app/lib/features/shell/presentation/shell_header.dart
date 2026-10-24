import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../auth/data/auth_repository.dart';
import '../../pos/presentation/pos_widgets.dart';

class ShellHeader extends StatelessWidget implements PreferredSizeWidget {
  final List<StoreItem> stores;
  final String? storeId;
  final String userName;
  final bool isDark;
  final ValueChanged<String>? onStoreChanged;
  final ValueChanged<Brightness> onBrightnessChanged;
  final VoidCallback? onSync;
  final VoidCallback? onLogout;

  const ShellHeader({
    super.key,
    required this.stores,
    required this.storeId,
    required this.userName,
    required this.isDark,
    required this.onStoreChanged,
    required this.onBrightnessChanged,
    required this.onSync,
    required this.onLogout,
  });

  @override
  Size get preferredSize => const Size.fromHeight(64);

  @override
  Widget build(BuildContext context) {
    final background = isDark ? const Color(0xFF121316) : Colors.white;
    final border = isDark ? const Color(0xFF292C35) : const Color(0xFFE3E5EB);
    final foreground =
        isDark ? const Color(0xFFF5F5F7) : const Color(0xFF20232B);
    final muted = isDark ? PosStyle.muted : const Color(0xFF6B7280);
    final storeName = stores
            .where((store) => store.id.toString() == storeId)
            .firstOrNull
            ?.name ??
        'Do‘kon';

    Widget action(
            IconData icon, String label, VoidCallback? onPressed) =>
        SizedBox(
            width: 36,
            height: 40,
            child: IconButton(
                tooltip: label,
                onPressed: onPressed,
                padding: EdgeInsets.zero,
                iconSize: 20,
                style: IconButton.styleFrom(
                    foregroundColor: foreground,
                    disabledForegroundColor: muted.withOpacity(.4),
                    side: BorderSide(color: border),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(11))),
                icon: Icon(icon)));

    Widget appearance(Brightness brightness, IconData icon, String label) {
      final selected = isDark == (brightness == Brightness.dark);
      return Expanded(
          child: Semantics(
              selected: selected,
              child: IconButton(
                  tooltip: label,
                  onPressed: () => onBrightnessChanged(brightness),
                  padding: EdgeInsets.zero,
                  iconSize: 18,
                  style: IconButton.styleFrom(
                      foregroundColor:
                          selected ? const Color(0xFFFFCB70) : muted,
                      backgroundColor: selected
                          ? const Color(0xFF805518)
                          : Colors.transparent,
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(9))),
                  icon: Icon(icon))));
    }

    return AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: background,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        systemOverlayStyle:
            isDark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
        toolbarHeight: preferredSize.height,
        titleSpacing: 14,
        title: Row(children: [
          ClipRRect(
              borderRadius: BorderRadius.circular(11),
              child: SizedBox(
                  width: 38,
                  height: 38,
                  child: Transform.scale(
                      scale: 1.36,
                      child: Image.asset('assets/images/logo.png',
                          semanticLabel: 'OsonPOS', fit: BoxFit.cover)))),
          const SizedBox(width: 6),
          Expanded(
              child: PopupMenuButton<String>(
                  tooltip: 'Do‘konni tanlash',
                  enabled: onStoreChanged != null && stores.isNotEmpty,
                  onSelected: onStoreChanged,
                  color: isDark ? PosStyle.card : Colors.white,
                  itemBuilder: (_) => stores
                      .map((store) => CheckedPopupMenuItem(
                          value: store.id.toString(),
                          checked: store.id.toString() == storeId,
                          child: Text(store.name)))
                      .toList(),
                  child: Container(
                      height: 40,
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      decoration: BoxDecoration(
                          border: Border.all(color: border),
                          borderRadius: BorderRadius.circular(11)),
                      child: Row(children: [
                        Expanded(
                            child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                              Text(storeName.toUpperCase(),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                      color: foreground,
                                      fontSize: 11,
                                      fontWeight: FontWeight.w700,
                                      letterSpacing: .2)),
                              Text(userName,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(color: muted, fontSize: 10)),
                            ])),
                        const SizedBox(width: 3),
                        Icon(Icons.expand_more, color: muted, size: 16),
                      ])))),
          const SizedBox(width: 8),
          Container(
              width: 72,
              height: 36,
              padding: const EdgeInsets.all(3),
              decoration: BoxDecoration(
                  border: Border.all(color: border),
                  borderRadius: BorderRadius.circular(11)),
              child: Row(children: [
                appearance(Brightness.light, Icons.light_mode_outlined,
                    'Kunduzgi rejim'),
                appearance(
                    Brightness.dark, Icons.dark_mode_rounded, 'Tungi rejim'),
              ])),
          const SizedBox(width: 8),
          action(Icons.sync_rounded, 'Server bilan sinxronlash', onSync),
          const SizedBox(width: 6),
          action(Icons.logout_rounded, 'Hisobdan chiqish', onLogout),
        ]));
  }
}
