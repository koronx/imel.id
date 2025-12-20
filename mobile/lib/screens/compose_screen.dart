import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../models/email_message.dart';

class ComposeScreen extends StatefulWidget {
  final EmailMessage? replyToEmail;

  const ComposeScreen({super.key, this.replyToEmail});

  @override
  State<ComposeScreen> createState() => _ComposeScreenState();
}

class _ComposeScreenState extends State<ComposeScreen> {
  final _formKey = GlobalKey<FormState>();
  final _toController = TextEditingController();
  final _subjectController = TextEditingController();
  final _bodyController = TextEditingController();
  final ApiService _apiService = ApiService();
  bool _isSending = false;

  @override
  void initState() {
    super.initState();
    if (widget.replyToEmail != null) {
      _toController.text = widget.replyToEmail!.sender;
      _subjectController.text = widget.replyToEmail!.subject.startsWith('Re: ')
          ? widget.replyToEmail!.subject
          : 'Re: ${widget.replyToEmail!.subject}';
      _bodyController.text = '\n\n--- Pesan asli ---\nDari: ${widget.replyToEmail!.sender}\nSubjek: ${widget.replyToEmail!.subject}\n\n${widget.replyToEmail!.body.replaceAll(RegExp(r'<[^>]*>'), '')}';
    }
  }

  @override
  void dispose() {
    _toController.dispose();
    _subjectController.dispose();
    _bodyController.dispose();
    super.dispose();
  }

  Future<void> _sendEmail() async {
    if (_formKey.currentState!.validate()) {
      setState(() {
        _isSending = true;
      });

      final result = await _apiService.sendEmail(
        to: _toController.text,
        subject: _subjectController.text,
        body: _bodyController.text,
      );

      setState(() {
        _isSending = false;
      });

      if (mounted) {
        if (result['success']) {
          Navigator.of(context).pop();
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Email berhasil dikirim'),
              backgroundColor: Colors.green,
            ),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(result['message'] ?? 'Gagal mengirim email'),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.replyToEmail != null ? 'Balas Email' : 'Tulis Email'),
        actions: [
          IconButton(
            icon: _isSending
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Icon(Icons.send),
            onPressed: _isSending ? null : _sendEmail,
          ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: Column(
          children: [
            // To Field
            Padding(
              padding: const EdgeInsets.all(8.0),
              child: TextFormField(
                controller: _toController,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(
                  labelText: 'Kepada',
                  prefixIcon: Icon(Icons.person),
                  border: OutlineInputBorder(),
                ),
                validator: (value) {
                  if (value == null || value.isEmpty) {
                    return 'Penerima harus diisi';
                  }
                  if (!value.contains('@')) {
                    return 'Email tidak valid';
                  }
                  return null;
                },
              ),
            ),
            
            // Subject Field
            Padding(
              padding: const EdgeInsets.all(8.0),
              child: TextFormField(
                controller: _subjectController,
                decoration: const InputDecoration(
                  labelText: 'Subjek',
                  prefixIcon: Icon(Icons.subject),
                  border: OutlineInputBorder(),
                ),
                validator: (value) {
                  if (value == null || value.isEmpty) {
                    return 'Subjek harus diisi';
                  }
                  return null;
                },
              ),
            ),
            
            // Body Field
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(8.0),
                child: TextFormField(
                  controller: _bodyController,
                  maxLines: null,
                  expands: true,
                  textAlignVertical: TextAlignVertical.top,
                  decoration: const InputDecoration(
                    labelText: 'Pesan',
                    alignLabelWithHint: true,
                    border: OutlineInputBorder(),
                  ),
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Pesan harus diisi';
                    }
                    return null;
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
