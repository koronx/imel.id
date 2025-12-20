import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../models/email_message.dart';
import 'compose_screen.dart';
import 'email_detail_screen.dart';
import 'login_screen.dart';

class InboxScreen extends StatefulWidget {
  const InboxScreen({super.key});

  @override
  State<InboxScreen> createState() => _InboxScreenState();
}

class _InboxScreenState extends State<InboxScreen> {
  final ApiService _apiService = ApiService();
  List<EmailMessage> _emails = [];
  bool _isLoading = true;
  int _currentTab = 0; // 0: Inbox, 1: Sent

  @override
  void initState() {
    super.initState();
    _loadEmails();
  }

  Future<void> _loadEmails() async {
    setState(() {
      _isLoading = true;
    });

    List<EmailMessage> emails;
    if (_currentTab == 0) {
      emails = await _apiService.getInbox();
    } else {
      emails = await _apiService.getSent();
    }

    setState(() {
      _emails = emails;
      _isLoading = false;
    });
  }

  Future<void> _handleLogout() async {
    final authProvider = context.read<AuthProvider>();
    await authProvider.logout();
    if (mounted) {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
  }

  Widget _buildQuotaInfo(AuthProvider authProvider) {
    final user = authProvider.user;
    if (user == null) return const SizedBox.shrink();
    
    final percent = user.quotaPercentage;
    final color = percent > 90 ? Colors.red : (percent > 75 ? Colors.orange : Colors.green);
    
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            const Icon(Icons.storage, size: 16),
            const SizedBox(width: 8),
            const Text('Storage', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
          ],
        ),
        const SizedBox(height: 4),
        LinearProgressIndicator(
          value: percent / 100,
          backgroundColor: Colors.grey[300],
          valueColor: AlwaysStoppedAnimation<Color>(color),
        ),
        const SizedBox(height: 4),
        Text(
          '${user.quotaUsedFormatted} / ${user.quotaTotalFormatted} (${percent.toStringAsFixed(1)}%)',
          style: const TextStyle(fontSize: 11),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final authProvider = context.watch<AuthProvider>();
    
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('imel.id'),
          actions: [
            IconButton(
              icon: const Icon(Icons.refresh),
              onPressed: _loadEmails,
            ),
            PopupMenuButton<String>(
              onSelected: (value) {
                if (value == 'logout') {
                  _handleLogout();
                }
              },
              itemBuilder: (context) => [
                PopupMenuItem(
                  child: Row(
                    children: [
                      const Icon(Icons.person),
                      const SizedBox(width: 8),
                      Text(authProvider.user?.email ?? ''),
                    ],
                  ),
                ),
                PopupMenuItem(
                  enabled: false,
                  child: _buildQuotaInfo(authProvider),
                ),
                const PopupMenuItem(
                  value: 'logout',
                  child: Row(
                    children: [
                      Icon(Icons.logout),
                      SizedBox(width: 8),
                      Text('Keluar'),
                    ],
                  ),
                ),
              ],
            ),
          ],
          bottom: TabBar(
            onTap: (index) {
              setState(() {
                _currentTab = index;
              });
              _loadEmails();
            },
            tabs: const [
              Tab(icon: Icon(Icons.inbox), text: 'Kotak Masuk'),
              Tab(icon: Icon(Icons.send), text: 'Terkirim'),
            ],
          ),
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : _emails.isEmpty
                ? Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          _currentTab == 0 ? Icons.inbox : Icons.send,
                          size: 64,
                          color: Colors.grey,
                        ),
                        const SizedBox(height: 16),
                        Text(
                          _currentTab == 0
                              ? 'Tidak ada email masuk'
                              : 'Tidak ada email terkirim',
                          style: const TextStyle(
                            fontSize: 16,
                            color: Colors.grey,
                          ),
                        ),
                      ],
                    ),
                  )
                : RefreshIndicator(
                    onRefresh: _loadEmails,
                    child: ListView.builder(
                      itemCount: _emails.length,
                      itemBuilder: (context, index) {
                        final email = _emails[index];
                        return EmailListItem(
                          email: email,
                          onTap: () {
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => EmailDetailScreen(emailId: email.id),
                              ),
                            ).then((_) => _loadEmails());
                          },
                        );
                      },
                    ),
                  ),
        floatingActionButton: FloatingActionButton(
          onPressed: () {
            Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const ComposeScreen()),
            ).then((_) => _loadEmails());
          },
          child: const Icon(Icons.edit),
        ),
      ),
    );
  }
}

class EmailListItem extends StatelessWidget {
  final EmailMessage email;
  final VoidCallback onTap;

  const EmailListItem({
    super.key,
    required this.email,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final dateFormat = DateFormat('dd MMM yyyy HH:mm');
    
    return ListTile(
      leading: CircleAvatar(
        backgroundColor: email.isRead ? Colors.grey : Theme.of(context).primaryColor,
        child: Text(
          email.sender[0].toUpperCase(),
          style: const TextStyle(color: Colors.white),
        ),
      ),
      title: Text(
        email.sender,
        style: TextStyle(
          fontWeight: email.isRead ? FontWeight.normal : FontWeight.bold,
        ),
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            email.subject,
            style: TextStyle(
              fontWeight: email.isRead ? FontWeight.normal : FontWeight.w600,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          Text(
            email.body.replaceAll(RegExp(r'<[^>]*>'), ''),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 12),
          ),
        ],
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            dateFormat.format(email.createdAt),
            style: const TextStyle(fontSize: 12),
          ),
          if (email.hasAttachment)
            const Icon(Icons.attach_file, size: 16),
        ],
      ),
      onTap: onTap,
    );
  }
}
