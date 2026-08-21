import { useState } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import {
  Alert, Box, Button, CircularProgress, Divider, InputAdornment, Link, Paper, Stack,
  TextField, Typography,
} from '@mui/material';
import { useAuth } from '../state/AuthContext';

const DOMAIN = import.meta.env.VITE_MAIL_DOMAIN || 'imel.id';

export default function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ full_name: '', username: '', password: '', confirm: '' });
  const [fields, setFields] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  const submit = async (event) => {
    event.preventDefault();
    setError('');
    setFields({});

    if (form.password !== form.confirm) {
      setFields({ confirm: 'Konfirmasi password tidak cocok' });
      return;
    }

    setBusy(true);
    try {
      await register({
        full_name: form.full_name.trim(),
        email: `${form.username.trim().toLowerCase()}@${DOMAIN}`,
        password: form.password,
      });
      navigate('/mail/inbox', { replace: true });
    } catch (err) {
      setError(err.message);
      setFields(err.fields || {});
    } finally {
      setBusy(false);
    }
  };

  return (
    <Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', p: 2, bgcolor: 'background.default' }}>
      <Paper variant="outlined" sx={{ width: '100%', maxWidth: 480, p: { xs: 3, sm: 6 }, borderRadius: 2 }}>
        <Stack spacing={1} alignItems="center" sx={{ mb: 3 }}>
          <Box component="img" src="/logo.png" alt="imel.id" sx={{ width: 64, height: 64, objectFit: 'contain' }} />
          <Typography variant="h5" sx={{ fontWeight: 400 }}>Buat akun imel.id</Typography>
          <Typography variant="body2" color="text.secondary">Gratis dan hanya butuh satu menit</Typography>
        </Stack>

        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

        <Box component="form" onSubmit={submit}>
          <Stack spacing={2.5}>
            <TextField
              label="Nama lengkap"
              value={form.full_name}
              onChange={set('full_name')}
              error={Boolean(fields.full_name)}
              helperText={fields.full_name}
              required
              autoFocus
              fullWidth
            />
            <TextField
              label="Nama pengguna"
              value={form.username}
              onChange={set('username')}
              error={Boolean(fields.email)}
              helperText={fields.email || 'Huruf kecil, angka, titik'}
              required
              fullWidth
              InputProps={{ endAdornment: <InputAdornment position="end">@{DOMAIN}</InputAdornment> }}
            />
            <TextField
              label="Password"
              type="password"
              value={form.password}
              onChange={set('password')}
              error={Boolean(fields.password)}
              helperText={fields.password || 'Minimal 8 karakter'}
              required
              fullWidth
            />
            <TextField
              label="Konfirmasi password"
              type="password"
              value={form.confirm}
              onChange={set('confirm')}
              error={Boolean(fields.confirm)}
              helperText={fields.confirm}
              required
              fullWidth
            />
            <Button type="submit" variant="contained" size="large" disabled={busy} sx={{ py: 1.25 }}>
              {busy ? <CircularProgress size={22} color="inherit" /> : 'Daftar'}
            </Button>
          </Stack>
        </Box>

        <Divider sx={{ my: 3 }} />

        <Stack direction="row" justifyContent="space-between" alignItems="center">
          <Typography variant="body2" color="text.secondary">Sudah punya akun?</Typography>
          <Link component={RouterLink} to="/login" underline="hover" sx={{ fontWeight: 500 }}>
            Masuk
          </Link>
        </Stack>
      </Paper>
    </Box>
  );
}
