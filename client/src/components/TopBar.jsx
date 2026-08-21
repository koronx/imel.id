import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Avatar, Box, Chip, Divider, IconButton, InputBase, Menu, MenuItem, Paper,
  ListItemIcon, ListItemText, Tooltip, Typography, useMediaQuery, useTheme,
} from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';
import SearchIcon from '@mui/icons-material/Search';
import TuneIcon from '@mui/icons-material/Tune';
import CloseIcon from '@mui/icons-material/Close';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import SettingsIcon from '@mui/icons-material/Settings';
import AppsIcon from '@mui/icons-material/Apps';
import CircleIcon from '@mui/icons-material/Circle';
import LogoutIcon from '@mui/icons-material/Logout';
import ManageAccountsIcon from '@mui/icons-material/ManageAccounts';
import RefreshIcon from '@mui/icons-material/Refresh';
import { useAuth } from '../state/AuthContext';
import { useMail } from '../state/MailContext';
import { colorFor, initials, formatBytes, quotaPercent } from '../utils/format';
import SearchFilterDialog from './SearchFilterDialog';

export default function TopBar({ onToggleSidebar }) {
  const theme = useTheme();
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const { query, search, refresh } = useMail();
  const [value, setValue] = useState(query);
  const [anchor, setAnchor] = useState(null);
  const [filterOpen, setFilterOpen] = useState(false);
  const inputRef = useRef(null);
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));

  useEffect(() => setValue(query), [query]);

  // "/" focuses search, the way Gmail does it
  useEffect(() => {
    const onKeyDown = (event) => {
      const tag = event.target.tagName;
      if (event.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA' && !event.target.isContentEditable) {
        event.preventDefault();
        inputRef.current?.focus();
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, []);

  const submit = (event) => {
    event.preventDefault();
    search(value.trim());
  };

  return (
    <Box
      component="header"
      sx={{
        display: 'flex', alignItems: 'center', gap: 1, px: { xs: 1, md: 2 }, py: 1,
        bgcolor: 'background.default', flexShrink: 0, zIndex: (t) => t.zIndex.appBar,
      }}
    >
      <IconButton onClick={onToggleSidebar} aria-label="Menu utama">
        <MenuIcon />
      </IconButton>

      <Box
        sx={{ display: 'flex', alignItems: 'center', gap: 1, mr: 2, cursor: 'pointer', userSelect: 'none' }}
        onClick={() => navigate('/mail/inbox')}
      >
        <Box component="img" src="/logo.png" alt="imel.id" sx={{ width: 34, height: 34, objectFit: 'contain' }} />
        {!isMobile && (
          <Typography sx={{ fontSize: 22, color: 'text.secondary', letterSpacing: -0.5 }}>
            imel.id
          </Typography>
        )}
      </Box>

      <Paper
        component="form"
        onSubmit={submit}
        sx={{
          flex: 1, maxWidth: 720, display: 'flex', alignItems: 'center', gap: 1,
          px: 1, py: 0.25, borderRadius: 6,
          bgcolor: theme.palette.mode === 'dark' ? '#3c4043' : '#eaf1fb',
          '&:focus-within': {
            bgcolor: 'background.paper',
            boxShadow: '0 1px 3px rgba(32,33,36,.28)',
          },
        }}
      >
        <IconButton type="submit" size="small" aria-label="Telusuri">
          <SearchIcon />
        </IconButton>
        <InputBase
          inputRef={inputRef}
          value={value}
          onChange={(event) => setValue(event.target.value)}
          placeholder="Telusuri email"
          sx={{ flex: 1, fontSize: 16 }}
          inputProps={{ 'aria-label': 'Telusuri email' }}
        />
        {value && (
          <IconButton size="small" onClick={() => { setValue(''); search(''); }} aria-label="Bersihkan">
            <CloseIcon fontSize="small" />
          </IconButton>
        )}
        <Tooltip title="Opsi penelusuran lanjutan">
          <IconButton size="small" onClick={() => setFilterOpen(true)} aria-label="Filter penelusuran">
            <TuneIcon />
          </IconButton>
        </Tooltip>
      </Paper>

      <Box sx={{ flex: { xs: 0, md: '0 0 auto' }, display: 'flex', alignItems: 'center', gap: 0.5, ml: 'auto' }}>
        {!isMobile && (
          <Chip
            icon={<CircleIcon sx={{ fontSize: 10, color: '#188038 !important' }} />}
            label="Aktif"
            variant="outlined"
            sx={{ height: 32, borderRadius: 4, mr: 1, fontSize: 13 }}
          />
        )}
        <Tooltip title="Muat ulang">
          <IconButton onClick={refresh}><RefreshIcon /></IconButton>
        </Tooltip>
        {!isMobile && (
          <>
            <Tooltip title="Bantuan">
              <IconButton><HelpOutlineIcon /></IconButton>
            </Tooltip>
            <Tooltip title="Setelan">
              <IconButton onClick={() => navigate('/settings')}><SettingsIcon /></IconButton>
            </Tooltip>
            <Tooltip title="Aplikasi imel.id">
              <IconButton><AppsIcon /></IconButton>
            </Tooltip>
          </>
        )}
        <Tooltip title={`${user?.full_name} (${user?.email})`}>
          <IconButton onClick={(event) => setAnchor(event.currentTarget)} sx={{ ml: 0.5 }}>
            <Avatar
              sx={{ width: 32, height: 32, bgcolor: user?.avatar_color || colorFor(user?.email || ''), fontSize: 14 }}
            >
              {initials(user?.full_name || user?.email)}
            </Avatar>
          </IconButton>
        </Tooltip>
      </Box>

      <Menu
        anchorEl={anchor}
        open={Boolean(anchor)}
        onClose={() => setAnchor(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{ paper: { sx: { width: 320, borderRadius: 3, p: 1 } } }}
      >
        <Box sx={{ textAlign: 'center', py: 2 }}>
          <Avatar
            sx={{
              width: 72, height: 72, mx: 'auto', mb: 1, fontSize: 28,
              bgcolor: user?.avatar_color || colorFor(user?.email || ''),
            }}
          >
            {initials(user?.full_name || user?.email)}
          </Avatar>
          <Typography sx={{ fontWeight: 500 }}>{user?.full_name}</Typography>
          <Typography variant="body2" color="text.secondary">{user?.email}</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
            {formatBytes(user?.used_bytes || 0)} dari {formatBytes(user?.quota_bytes || 0)} terpakai
            {' · '}
            {quotaPercent(user?.used_bytes, user?.quota_bytes)}%
          </Typography>
        </Box>
        <Divider />
        <MenuItem onClick={() => { setAnchor(null); navigate('/settings'); }}>
          <ListItemIcon><ManageAccountsIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Kelola akun</ListItemText>
        </MenuItem>
        <MenuItem onClick={() => { setAnchor(null); logout(); }}>
          <ListItemIcon><LogoutIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Keluar</ListItemText>
        </MenuItem>
      </Menu>

      <SearchFilterDialog
        open={filterOpen}
        onClose={() => setFilterOpen(false)}
        onApply={(built) => { setValue(built); search(built); setFilterOpen(false); }}
      />
    </Box>
  );
}
