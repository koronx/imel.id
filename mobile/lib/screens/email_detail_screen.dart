import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../services/api_service.dart';
import '../models/email_message.dart';
import 'compose_screen.dart';

class EmailDetailScreen extends StatefulWidget {
  final int emailId;

  const EmailDetailScreen({super.key, required this.emailId});

  @override
  State<EmailDetailScreen> createState() => _EmailDetailScreenState();
}

class _EmailDetailScreenState extends State<EmailDetailScreen> {
  final ApiService _apiService = ApiService();
  EmailMessage? _email;
  List<dynamic> _attachments = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadEmail();
  }

  Future<void> _loadEmail() async {
    setState(() {
      _isLoading = true;
    });

    final result = await _apiService.getEmailDetail(widget.emailId);
    
    if (result['success']) {
      await _apiService.markAsRead(widget.emailId);
      setState(() {
        _email = result['email'];
        _attachments = result['attachments'] ?? [];
        _isLoading = false;
      });
    } else {
      setState(() {
        _isLoading = false;
      });
    }
  }

  Future<void> _deleteEmail() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Hapus Email'),
        content: const Text('Apakah Anda yakin ingin menghapus email ini?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Batal'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      final success = await _apiService.deleteEmail(widget.emailId);
      if (mounted) {
        if (success) {
          Navigator.of(context).pop();
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Email berhasil dihapus')),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Gagal menghapus email')),
          );
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final dateFormat = DateFormat('dd MMMM yyyy, HH:mm');

    return Scaffold(
      appBar: AppBar(
        title: const Text('Detail Email'),
        actions: [
          IconButton(
            icon: const Icon(Icons.reply),
            onPressed: _email == null ? null : () {
              Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (context) => ComposeScreen(
                    replyToEmail: _email,
                  ),
                ),
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.delete),
            onPressed: _deleteEmail,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _email == null
              ? const Center(child: Text('Email tidak ditemukan'))
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Subject
                      Text(
                        _email!.subject,
                        style: const TextStyle(
                          fontSize: 24,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 16),
                      
                      // From
                      Row(
                        children: [
                          CircleAvatar(
                            backgroundColor: Theme.of(context).primaryColor,
                            child: Text(
                              _email!.sender[0].toUpperCase(),
                              style: const TextStyle(color: Colors.white),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  _email!.sender,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                                Text(
                                  'Kepada: ${_email!.recipient}',
                                  style: const TextStyle(
                                    color: Colors.grey,
                                    fontSize: 12,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Text(
                        dateFormat.format(_email!.createdAt),
                        style: const TextStyle(
                          color: Colors.grey,
                          fontSize: 12,
                        ),
                      ),
                      const Divider(height: 32),
                      
                      // Body
                      Text(
                        _email!.body.replaceAll(RegExp(r'<[^>]*>'), '\n'),
                        style: const TextStyle(fontSize: 16),
                      ),
                      
                      // Attachments
                      if (_attachments.isNotEmpty) ...[
                        const Divider(height: 32),
                        const Text(
                          'Lampiran',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 8),
                        ..._attachments.map((attachment) {
                          return Card(
                            child: ListTile(
                              leading: const Icon(Icons.attach_file),
                              title: Text(attachment['filename']),
                              subtitle: Text(attachment['mime_type']),
                              trailing: IconButton(
                                icon: const Icon(Icons.download),
                                onPressed: () {
                                  // TODO: Implement download
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(
                                      content: Text('Download akan segera tersedia'),
                                    ),
                                  );
                                },
                              ),
                            ),
                          );
                        }),
                      ],
                    ],
                  ),
                ),
      floatingActionButton: _email == null
          ? null
          : FloatingActionButton.extended(
              onPressed: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (context) => ComposeScreen(
                      replyToEmail: _email,
                    ),
                  ),
                );
              },
              icon: const Icon(Icons.reply),
              label: const Text('Balas'),
            ),
    );
  }
}
