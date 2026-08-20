import { useState } from 'react';
import {
  Avatar, Box, Button, Chip, Divider, IconButton, ListItemIcon, ListItemText,
  Menu, MenuItem, Paper, Tooltip, Typography,
} from '@mui/material';
import StarIcon from '@mui/icons-material/Star';
import StarBorderIcon from '@mui/icons-material/StarBorder';
import ReplyIcon from '@mui/icons-material/Reply';
import ReplyAllIcon from '@mui/icons-material/ReplyAll';
import ForwardIcon from '@mui/icons-material/Forward';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import PrintIcon from '@mui/icons-material/Print';
import CodeIcon from '@mui/icons-material/Code';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import MarkEmailUnreadOutlinedIcon from '@mui/icons-material/MarkEmailUnreadOutlined';
import DownloadIcon from '@mui/icons-material/Download';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import EditIcon from '@mui/icons-material/Edit';
import { api } from '../api/client';
import { colorFor, formatBytes, fullDate, initials, listDate } from '../utils/format';
import MessageBody from './MessageBody';

export default function MessageItem({
  message, expanded, onToggle, onReply, onStar, onTrash, onUnread, onEditDraft, me,
}) {
  const [menu, setMenu] = useState(null);
  const [rawOpen, setRawOpen] = useState(false);
  const [raw, setRaw] = useState('');

  const senderName = message.from.name || message.from.email;
  const recipients = [...message.to, ...message.cc];

  const showRaw = async () => {
    setMenu(null);
    try {
      const data = await api.messageRaw(message.id);
      setRaw(data.raw || '(sumber asli tidak tersimpan untuk pesan ini)');
      setRawOpen(true);
    } catch {
      setRaw('Gagal memuat sumber pesan.');
      setRawOpen(true);
    }
  };

  if (!expanded) {
    return (
      <Box
        onClick={onToggle}
        sx={{
          display: 'flex', alignItems: 'center', gap: 1.5, px: 3, py: 1.5, cursor: 'pointer',
          borderBottom: '1px solid', borderColor: 'divider',
          '&:hover': { bgcolor: 'action.hover' },
        }}
      >
        <Avatar sx={{ width: 32, height: 32, fontSize: 13, bgcolor: colorFor(message.from.email) }}>
          {initials(senderName)}
        </Avatar>
        <Typography sx={{ fontWeight: 500, fontSize: 14, flexShrink: 0 }}>{senderName}</Typography>
        <Typography
          variant="body2"
          color="text.secondary"
          sx={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
        >
          {message.snippet}
        </Typography>
        {message.has_attachment && <AttachFileIcon fontSize="small" sx={{ color: 'text.secondary' }} />}
        <Typography variant="caption" color="text.secondary">{listDate(message.date)}</Typography>
      </Box>
    );
  }

  return (
    <Box sx={{ px: { xs: 2, md: 3 }, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
      <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2 }}>
        <Avatar sx={{ width: 40, height: 40, bgcolor: colorFor(message.from.email) }}>
          {initials(senderName)}
        </Avatar>

        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ display: 'flex', alignItems: 'baseline', gap: 1, flexWrap: 'wrap' }}>
            <Typography sx={{ fontWeight: 700, fontSize: 14 }}>{senderName}</Typography>
            <Typography variant="caption" color="text.secondary">
              &lt;{message.from.email}&gt;
            </Typography>
            {message.is_draft && <Chip size="small" color="error" label="Draf" />}
            <Box sx={{ ml: 'auto', display: 'flex', alignItems: 'center', gap: 0.5 }}>
              <Typography variant="caption" color="text.secondary">{fullDate(message.date)}</Typography>
              <Tooltip title="Beri bintang">
                <IconButton size="small" onClick={() => onStar(!message.is_starred)}>
                  {message.is_starred
                    ? <StarIcon fontSize="small" sx={{ color: '#f4b400' }} />
                    : <StarBorderIcon fontSize="small" />}
                </IconButton>
              </Tooltip>
              <Tooltip title="Balas">
                <IconButton size="small" onClick={() => onReply('reply')}>
                  <ReplyIcon fontSize="small" />
                </IconButton>
              </Tooltip>
              <IconButton size="small" onClick={(event) => setMenu(event.currentTarget)}>
                <MoreVertIcon fontSize="small" />
              </IconButton>
            </Box>
          </Box>

          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
            kepada {recipients.map((email) => (email === me ? 'saya' : email)).join(', ') || '(tanpa penerima)'}
          </Typography>

          <Box sx={{ mt: 1.5 }}>
            <MessageBody html={message.body_html} text={message.body_text} />
          </Box>

          {message.attachments?.length > 0 && (
            <Box sx={{ mt: 2 }}>
              <Divider sx={{ mb: 1.5 }} />
              <Typography variant="caption" color="text.secondary">
                {message.attachments.length} lampiran
              </Typography>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, mt: 1 }}>
                {message.attachments.map((attachment) => (
                  <Paper
                    key={attachment.id}
                    variant="outlined"
                    sx={{
                      width: 220, p: 1.25, display: 'flex', alignItems: 'center', gap: 1,
                      borderRadius: 2, '&:hover': { bgcolor: 'action.hover' },
                    }}
                  >
                    <AttachFileIcon fontSize="small" sx={{ color: 'text.secondary' }} />
                    <Box sx={{ minWidth: 0, flex: 1 }}>
                      <Typography variant="body2" noWrap title={attachment.filename}>
                        {attachment.filename}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {formatBytes(attachment.size_bytes)}
                      </Typography>
                    </Box>
                    <Tooltip title="Unduh">
                      <IconButton
                        size="small"
                        component="a"
                        href={api.attachmentUrl(attachment.id)}
                        download={attachment.filename}
                      >
                        <DownloadIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </Paper>
                ))}
              </Box>
            </Box>
          )}

          {message.is_draft ? (
            <Button startIcon={<EditIcon />} sx={{ mt: 2 }} variant="outlined" onClick={onEditDraft}>
              Lanjutkan menulis
            </Button>
          ) : (
            <Box sx={{ display: 'flex', gap: 1, mt: 2 }}>
              <Button startIcon={<ReplyIcon />} variant="outlined" onClick={() => onReply('reply')}>
                Balas
              </Button>
              {recipients.length > 1 && (
                <Button startIcon={<ReplyAllIcon />} variant="outlined" onClick={() => onReply('reply_all')}>
                  Balas semua
                </Button>
              )}
              <Button startIcon={<ForwardIcon />} variant="outlined" onClick={() => onReply('forward')}>
                Teruskan
              </Button>
            </Box>
          )}
        </Box>
      </Box>

      <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
        <MenuItem onClick={() => { setMenu(null); onReply('reply_all'); }}>
          <ListItemIcon><ReplyAllIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Balas semua</ListItemText>
        </MenuItem>
        <MenuItem onClick={() => { setMenu(null); onReply('forward'); }}>
          <ListItemIcon><ForwardIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Teruskan</ListItemText>
        </MenuItem>
        <MenuItem onClick={() => { setMenu(null); onUnread(); }}>
          <ListItemIcon><MarkEmailUnreadOutlinedIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Tandai belum dibaca</ListItemText>
        </MenuItem>
        <Divider />
        <MenuItem onClick={showRaw}>
          <ListItemIcon><CodeIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Tampilkan sumber asli</ListItemText>
        </MenuItem>
        <MenuItem onClick={() => { setMenu(null); window.print(); }}>
          <ListItemIcon><PrintIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Cetak</ListItemText>
        </MenuItem>
        <MenuItem onClick={() => { setMenu(null); onTrash(); }}>
          <ListItemIcon><DeleteOutlineIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Hapus pesan ini</ListItemText>
        </MenuItem>
      </Menu>

      {rawOpen && (
        <Paper
          variant="outlined"
          sx={{ mt: 2, p: 2, maxHeight: 320, overflow: 'auto', bgcolor: 'action.hover' }}
        >
          <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
            <Typography variant="subtitle2" sx={{ flex: 1 }}>Sumber asli</Typography>
            <Button size="small" onClick={() => setRawOpen(false)}>Tutup</Button>
          </Box>
          <Box component="pre" sx={{ m: 0, fontSize: 11, whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
            {raw}
          </Box>
        </Paper>
      )}
    </Box>
  );
}
