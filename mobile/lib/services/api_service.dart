import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../models/user.dart';
import '../models/email_message.dart';

class ApiService {
  // Update dengan base URL sesuai dengan server Anda
  static const String baseUrl = 'http://localhost/api.php';
  
  final _storage = const FlutterSecureStorage();

  // Save token
  Future<void> saveToken(String token) async {
    await _storage.write(key: 'auth_token', value: token);
  }

  // Get token
  Future<String?> getToken() async {
    return await _storage.read(key: 'auth_token');
  }

  // Delete token
  Future<void> deleteToken() async {
    await _storage.delete(key: 'auth_token');
  }

  // Get headers with auth token
  Future<Map<String, String>> _getHeaders() async {
    final token = await getToken();
    return {
      'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };
  }

  // Login
  Future<Map<String, dynamic>> login(String email, String password) async {
    try {
      final response = await http.post(
        Uri.parse('$baseUrl?action=login'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'email': email,
          'password': password,
        }),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success']) {
          await saveToken(data['token']);
          return {'success': true, 'user': User.fromJson(data['user'])};
        } else {
          return {'success': false, 'message': data['message']};
        }
      } else {
        return {'success': false, 'message': 'Server error: ${response.statusCode}'};
      }
    } catch (e) {
      return {'success': false, 'message': 'Network error: $e'};
    }
  }

  // Register
  Future<Map<String, dynamic>> register(String email, String password, String fullName) async {
    try {
      final response = await http.post(
        Uri.parse('$baseUrl?action=register'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'email': email,
          'password': password,
          'full_name': fullName,
        }),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data;
      } else {
        return {'success': false, 'message': 'Server error: ${response.statusCode}'};
      }
    } catch (e) {
      return {'success': false, 'message': 'Network error: $e'};
    }
  }

  // Logout
  Future<void> logout() async {
    await deleteToken();
  }

  // Get current user
  Future<Map<String, dynamic>> getCurrentUser() async {
    try {
      final headers = await _getHeaders();
      final response = await http.get(
        Uri.parse('$baseUrl?action=get_user'),
        headers: headers,
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success']) {
          return {'success': true, 'user': User.fromJson(data['user'])};
        }
      }
      return {'success': false};
    } catch (e) {
      return {'success': false, 'message': 'Network error: $e'};
    }
  }

  // Get inbox emails
  Future<List<EmailMessage>> getInbox({int page = 1, int limit = 20}) async {
    try {
      final headers = await _getHeaders();
      final response = await http.get(
        Uri.parse('$baseUrl?action=get_inbox&page=$page&limit=$limit'),
        headers: headers,
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success']) {
          return (data['emails'] as List)
              .map((email) => EmailMessage.fromJson(email))
              .toList();
        }
      }
      return [];
    } catch (e) {
      return [];
    }
  }

  // Get sent emails
  Future<List<EmailMessage>> getSent({int page = 1, int limit = 20}) async {
    try {
      final headers = await _getHeaders();
      final response = await http.get(
        Uri.parse('$baseUrl?action=get_sent&page=$page&limit=$limit'),
        headers: headers,
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success']) {
          return (data['emails'] as List)
              .map((email) => EmailMessage.fromJson(email))
              .toList();
        }
      }
      return [];
    } catch (e) {
      return [];
    }
  }

  // Get email detail
  Future<Map<String, dynamic>> getEmailDetail(int emailId) async {
    try {
      final headers = await _getHeaders();
      final response = await http.get(
        Uri.parse('$baseUrl?action=get_email&id=$emailId'),
        headers: headers,
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success']) {
          return {
            'success': true,
            'email': EmailMessage.fromJson(data['email']),
            'attachments': data['attachments'],
          };
        }
      }
      return {'success': false};
    } catch (e) {
      return {'success': false, 'message': 'Network error: $e'};
    }
  }

  // Send email
  Future<Map<String, dynamic>> sendEmail({
    required String to,
    required String subject,
    required String body,
    List<String>? cc,
    List<String>? bcc,
  }) async {
    try {
      final headers = await _getHeaders();
      final response = await http.post(
        Uri.parse('$baseUrl?action=send_email'),
        headers: headers,
        body: jsonEncode({
          'to': to,
          'subject': subject,
          'body': body,
          if (cc != null) 'cc': cc,
          if (bcc != null) 'bcc': bcc,
        }),
      );

      if (response.statusCode == 200) {
        return jsonDecode(response.body);
      } else {
        return {'success': false, 'message': 'Server error: ${response.statusCode}'};
      }
    } catch (e) {
      return {'success': false, 'message': 'Network error: $e'};
    }
  }

  // Mark email as read
  Future<bool> markAsRead(int emailId) async {
    try {
      final headers = await _getHeaders();
      final response = await http.post(
        Uri.parse('$baseUrl?action=mark_read'),
        headers: headers,
        body: jsonEncode({'email_id': emailId}),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['success'] ?? false;
      }
      return false;
    } catch (e) {
      return false;
    }
  }

  // Delete email
  Future<bool> deleteEmail(int emailId) async {
    try {
      final headers = await _getHeaders();
      final response = await http.post(
        Uri.parse('$baseUrl?action=delete_email'),
        headers: headers,
        body: jsonEncode({'email_id': emailId}),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['success'] ?? false;
      }
      return false;
    } catch (e) {
      return false;
    }
  }
}
