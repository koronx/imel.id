import { useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom';
import {
  Alert, Box, Button, CircularProgress, Divider, Link, Paper, Stack, TextField, Typography,
} from '@mui/material';
import { useAuth } from '../state/AuthContext';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await login(email.trim(), password);
      navigate(location.state?.from?.pathname || '/mail/inbox', { replace: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Box
      sx={{
        minHeight: '100vh', display: 'grid', placeItems: 'center', p: 2,
        bgcolor: 'background.default',
      }}
    >
      <Paper
        variant="outlined"
        sx={{ width: '100%', maxWidth: 448, p: { xs: 3, sm: 6 }, borderRadius: 2 }}
      >
        <Stack spacing={1} alignItems="center" sx={{ mb: 3 }}>
          <Box component="img" src="/logo.png" alt="imel.id" sx={{ width: 64, height: 64, objectFit: 'contain' }} />
          <Typography variant="h5" sx={{ fontWeight: 400 }}>Masuk</Typography>
          <Typography variant="body2" color="text.secondary">
            Lanjutkan ke imel.id Mail
          </Typography>
        </Stack>

        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

        <Box component="form" onSubmit={submit}>
          <Stack spacing={2.5}>
            <TextField
              label="Email"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              autoFocus
              required
              fullWidth
              placeholder="nama@imel.id"
            />
            <TextField
              label="Password"
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              fullWidth
            />
            <Button type="submit" variant="contained" size="large" disabled={busy} sx={{ py: 1.25 }}>
              {busy ? <CircularProgress size={22} color="inherit" /> : 'Berikutnya'}
            </Button>
          </Stack>
        </Box>

        <Divider sx={{ my: 3 }} />

        <Stack direction="row" justifyContent="space-between" alignItems="center">
          <Typography variant="body2" color="text.secondary">Belum punya akun?</Typography>
          <Link component={RouterLink} to="/register" underline="hover" sx={{ fontWeight: 500 }}>
            Buat akun
          </Link>
        </Stack>

        <Alert severity="info" sx={{ mt: 3, fontSize: 13 }}>
          Akun demo: <strong>admin@imel.id</strong> / <strong>password123</strong>
        </Alert>
      </Paper>
    </Box>
  );
}
