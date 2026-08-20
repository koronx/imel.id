import { useEffect, useState } from 'react';
import {
  Alert, Box, Button, Chip, CircularProgress, Divider, FormControl, FormControlLabel,
  IconButton, InputLabel, MenuItem, Paper, Radio, RadioGroup, Select, Stack, Switch,
  Tab, Tabs, TextField, Typography,
} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import LogoutIcon from '@mui/icons-material/Logout';
import { api } from '../api/client';
import { useAuth } from '../state/AuthContext';
import { useMail } from '../state/MailContext';
import { fullDate } from '../utils/format';

function Section({ title, description, children }) {
  return (
    <Box sx={{ py: 2.5 }}>
      <Typography sx={{ fontWeight: 500, mb: 0.5 }}>{title}</Typography>
      {description && (
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{description}</Typography>
      )}
      {children}
    </Box>
  );
}

export default function SettingsPage() {
  const { user, settings, saveSettings, saveProfile, logout } = useAuth();
  const { openLabel, notify } = useMail();
  const [tab, setTab] = useState('general');
  const [draft, setDraft] = useState(settings);
  const [profile, setProfile] = useState({ full_name: user?.full_name || '' });
  const [passwords, setPasswords] = useState({ current_password: '', new_password: '', confirm: '' });
  const [sessions, setSessions] = useState([]);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState('');

  useEffect(() => setDraft(settings), [settings]);

  useEffect(() => {
    if (tab !== 'security') return;
    api.sessions().then((data) => setSessions(data.sessions || [])).catch(() => setSessions([]));
  }, [tab]);

  const set = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    setDraft((current) => ({ ...current, [key]: value }));
  };

  const persist = async () => {
    setBusy(true);
    try {
      await saveSettings(draft);
      setSaved('Setelan disimpan');
    } catch (err) {
      notify(err.message);
    } finally {
      setBusy(false);
    }
  };

  const persistProfile = async () => {
    setBusy(true);
    try {
      await saveProfile(profile);
      setSaved('Profil diperbarui');
    } catch (err) {
      notify(err.message);
    } finally {
      setBusy(false);
    }
  };

  const changePassword = async () => {
    if (passwords.new_password !== passwords.confirm) {
      notify('Konfirmasi password tidak cocok');
      return;
    }
    setBusy(true);
    try {
      await api.changePassword({
        current_password: passwords.current_password,
        new_password: passwords.new_password,
      });
      setPasswords({ current_password: '', new_password: '', confirm: '' });
      setSaved('Password diperbarui, perangkat lain telah dikeluarkan');
    } catch (err) {
      notify(err.fields?.current_password || err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0 }}>
      <Box
        sx={{
          display: 'flex', alignItems: 'center', gap: 1, px: 1, py: 0.5, minHeight: 48,
          borderBottom: '1px solid', borderColor: 'divider',
        }}
      >
        <IconButton onClick={() => openLabel('inbox')}><ArrowBackIcon /></IconButton>
        <Typography variant="h6" sx={{ fontWeight: 400, fontSize: 20 }}>Setelan</Typography>
      </Box>

      <Tabs
        value={tab}
        onChange={(event, next) => setTab(next)}
        sx={{ borderBottom: '1px solid', borderColor: 'divider', px: 2 }}
      >
        <Tab value="general" label="Umum" sx={{ textTransform: 'none' }} />
        <Tab value="compose" label="Menulis" sx={{ textTransform: 'none' }} />
        <Tab value="account" label="Akun" sx={{ textTransform: 'none' }} />
        <Tab value="security" label="Keamanan" sx={{ textTransform: 'none' }} />
      </Tabs>

      <Box sx={{ flex: 1, overflowY: 'auto', px: { xs: 2, md: 4 }, py: 2, maxWidth: 860 }}>
        {saved && <Alert severity="success" onClose={() => setSaved('')} sx={{ mb: 2 }}>{saved}</Alert>}

        {tab === 'general' && (
          <Stack divider={<Divider />}>
            <Section title="Tema" description="Sesuaikan tampilan dengan preferensi Anda.">
              <RadioGroup row value={draft.theme} onChange={set('theme')}>
                <FormControlLabel value="light" control={<Radio />} label="Terang" />
                <FormControlLabel value="dark" control={<Radio />} label="Gelap" />
                <FormControlLabel value="system" control={<Radio />} label="Ikuti sistem" />
              </RadioGroup>
            </Section>

            <Section title="Kepadatan tampilan" description="Jarak antar baris pada daftar email.">
              <RadioGroup row value={draft.density} onChange={set('density')}>
                <FormControlLabel value="default" control={<Radio />} label="Standar" />
                <FormControlLabel value="comfortable" control={<Radio />} label="Nyaman" />
                <FormControlLabel value="compact" control={<Radio />} label="Padat" />
              </RadioGroup>
            </Section>

            <Section title="Daftar percakapan">
              <Stack spacing={2} sx={{ maxWidth: 320 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Email per halaman</InputLabel>
                  <Select value={draft.per_page} label="Email per halaman" onChange={set('per_page')}>
                    {[15, 25, 50, 100].map((value) => (
                      <MenuItem key={value} value={value}>{value}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <FormControlLabel
                  control={<Switch checked={Boolean(draft.conversation_view)} onChange={set('conversation_view')} />}
                  label="Tampilan percakapan"
                />
              </Stack>
            </Section>
          </Stack>
        )}

        {tab === 'compose' && (
          <Stack divider={<Divider />}>
            <Section title="Batalkan pengiriman" description="Jeda sebelum pesan benar-benar dikirim.">
              <FormControl size="small" sx={{ width: 220 }}>
                <InputLabel>Periode pembatalan</InputLabel>
                <Select value={draft.undo_send_seconds} label="Periode pembatalan" onChange={set('undo_send_seconds')}>
                  {[0, 5, 10, 20, 30].map((value) => (
                    <MenuItem key={value} value={value}>{value} detik</MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Section>

            <Section title="Tanda tangan" description="Ditambahkan otomatis di akhir pesan baru.">
              <FormControlLabel
                control={<Switch checked={Boolean(draft.signature_enabled)} onChange={set('signature_enabled')} />}
                label="Aktifkan tanda tangan"
              />
              <TextField
                multiline
                minRows={3}
                fullWidth
                value={draft.signature}
                onChange={set('signature')}
                placeholder={`${user?.full_name}\nimel.id`}
                sx={{ mt: 1.5 }}
              />
            </Section>

            <Section title="Balasan otomatis" description="Kirim balasan saat Anda sedang tidak di tempat.">
              <FormControlLabel
                control={<Switch checked={Boolean(draft.vacation_enabled)} onChange={set('vacation_enabled')} />}
                label="Aktifkan balasan otomatis"
              />
              <Stack spacing={2} sx={{ mt: 1.5 }}>
                <TextField
                  label="Subjek"
                  size="small"
                  value={draft.vacation_subject || ''}
                  onChange={set('vacation_subject')}
                  disabled={!draft.vacation_enabled}
                  fullWidth
                />
                <TextField
                  label="Pesan"
                  multiline
                  minRows={3}
                  value={draft.vacation_body || ''}
                  onChange={set('vacation_body')}
                  disabled={!draft.vacation_enabled}
                  fullWidth
                />
              </Stack>
            </Section>
          </Stack>
        )}

        {tab === 'account' && (
          <Stack divider={<Divider />}>
            <Section title="Profil">
              <Stack spacing={2} sx={{ maxWidth: 420 }}>
                <TextField label="Alamat email" value={user?.email || ''} disabled fullWidth size="small" />
                <TextField
                  label="Nama tampilan"
                  value={profile.full_name}
                  onChange={(event) => setProfile({ full_name: event.target.value })}
                  fullWidth
                  size="small"
                />
                <Box>
                  <Button variant="contained" onClick={persistProfile} disabled={busy}>
                    Simpan profil
                  </Button>
                </Box>
              </Stack>
            </Section>

            <Section title="Ubah password">
              <Stack spacing={2} sx={{ maxWidth: 420 }}>
                <TextField
                  label="Password saat ini"
                  type="password"
                  size="small"
                  value={passwords.current_password}
                  onChange={(event) => setPasswords({ ...passwords, current_password: event.target.value })}
                  fullWidth
                />
                <TextField
                  label="Password baru"
                  type="password"
                  size="small"
                  value={passwords.new_password}
                  onChange={(event) => setPasswords({ ...passwords, new_password: event.target.value })}
                  fullWidth
                />
                <TextField
                  label="Konfirmasi password baru"
                  type="password"
                  size="small"
                  value={passwords.confirm}
                  onChange={(event) => setPasswords({ ...passwords, confirm: event.target.value })}
                  fullWidth
                />
                <Box>
                  <Button variant="contained" onClick={changePassword} disabled={busy}>
                    Perbarui password
                  </Button>
                </Box>
              </Stack>
            </Section>

            <Section title="Keluar dari akun ini">
              <Button color="error" variant="outlined" startIcon={<LogoutIcon />} onClick={logout}>
                Keluar
              </Button>
            </Section>
          </Stack>
        )}

        {tab === 'security' && (
          <Stack divider={<Divider />}>
            <Section title="Sesi aktif" description="Perangkat yang sedang masuk ke akun Anda.">
              <Stack spacing={1.5}>
                {sessions.length === 0 && <Typography variant="body2" color="text.secondary">Memuat...</Typography>}
                {sessions.map((session) => (
                  <Paper key={session.jti} variant="outlined" sx={{ p: 2, display: 'flex', gap: 2, alignItems: 'center' }}>
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Typography variant="body2" noWrap>{session.user_agent || 'Perangkat tidak dikenal'}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {session.ip_address} · terakhir aktif {fullDate(session.last_seen)}
                      </Typography>
                    </Box>
                    {session.current
                      ? <Chip size="small" color="success" label="Sesi ini" />
                      : (
                        <Button
                          size="small"
                          color="error"
                          onClick={async () => {
                            await api.revokeSession(session.jti);
                            setSessions((current) => current.filter((item) => item.jti !== session.jti));
                          }}
                        >
                          Keluarkan
                        </Button>
                      )}
                  </Paper>
                ))}
              </Stack>
            </Section>

            <Section title="Akses IMAP / SMTP" description="Gunakan kredensial ini pada aplikasi email lain.">
              <Box component="pre" sx={{ fontSize: 12, m: 0, p: 2, bgcolor: 'action.hover', borderRadius: 1 }}>
                {`IMAP  : host mail server, port 143, tanpa SSL\n`}
                {`SMTP  : host mail server, port 587, AUTH LOGIN\n`}
                {`User  : ${user?.email}\n`}
                {'Pass  : password akun Anda'}
              </Box>
            </Section>
          </Stack>
        )}

        {(tab === 'general' || tab === 'compose') && (
          <Box sx={{ position: 'sticky', bottom: 0, py: 2, bgcolor: 'background.paper' }}>
            <Button variant="contained" onClick={persist} disabled={busy}>
              {busy ? <CircularProgress size={20} color="inherit" /> : 'Simpan perubahan'}
            </Button>
          </Box>
        )}
      </Box>
    </Box>
  );
}
