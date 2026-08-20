import { memo } from 'react';
import {
  Box, Checkbox, Chip, IconButton, Tooltip, Typography,
} from '@mui/material';
import StarIcon from '@mui/icons-material/Star';
import StarBorderIcon from '@mui/icons-material/StarBorder';
import LabelImportantIcon from '@mui/icons-material/LabelImportant';
import LabelImportantOutlinedIcon from '@mui/icons-material/LabelImportantOutlined';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import MarkEmailUnreadOutlinedIcon from '@mui/icons-material/MarkEmailUnreadOutlined';
import DraftsOutlinedIcon from '@mui/icons-material/DraftsOutlined';
import AccessTimeIcon from '@mui/icons-material/AccessTime';
import { listDate, senderSummary } from '../utils/format';
import { rowHeight } from '../theme';

function ThreadRow({
  thread, rowKey, target, selected, density, me, onOpen, onToggle, onStar, onImportant, actions, label,
}) {
  const unread = !thread.is_read;
  const height = rowHeight(density);
  const chips = (thread.last_message.labels || []).filter((item) => item.type === 'user');

  const stop = (fn) => (event) => {
    event.stopPropagation();
    fn();
  };

  return (
    <Box
      onClick={() => onOpen(thread)}
      sx={{
        display: 'flex', alignItems: 'center', gap: 0.5, px: 1, height,
        cursor: 'pointer', borderBottom: '1px solid', borderColor: 'divider',
        bgcolor: selected ? 'action.selected' : unread ? 'background.paper' : 'transparent',
        fontWeight: unread ? 700 : 400,
        '&:hover': {
          boxShadow: 'inset 1px 0 0 #dadce0, inset -1px 0 0 #dadce0, 0 1px 2px 0 rgba(60,64,67,.3)',
          zIndex: 1,
          '& .row-date': { display: 'none' },
          '& .row-actions': { display: 'flex' },
        },
      }}
    >
      <Checkbox
        size="small"
        checked={selected}
        onClick={(event) => event.stopPropagation()}
        onChange={() => onToggle(rowKey)}
        sx={{ p: 0.5 }}
        inputProps={{ 'aria-label': `Pilih ${thread.last_message.subject}` }}
      />

      <Tooltip title={thread.is_starred ? 'Hapus bintang' : 'Beri bintang'}>
        <IconButton size="small" onClick={stop(() => onStar(thread, !thread.is_starred))} sx={{ p: 0.5 }}>
          {thread.is_starred
            ? <StarIcon fontSize="small" sx={{ color: '#f4b400' }} />
            : <StarBorderIcon fontSize="small" />}
        </IconButton>
      </Tooltip>

      <Tooltip title={thread.is_important ? 'Tidak penting' : 'Tandai penting'}>
        <IconButton
          size="small"
          onClick={stop(() => onImportant(thread, !thread.is_important))}
          sx={{ p: 0.5, display: { xs: 'none', sm: 'inline-flex' } }}
        >
          {thread.is_important
            ? <LabelImportantIcon fontSize="small" sx={{ color: '#f4b400' }} />
            : <LabelImportantOutlinedIcon fontSize="small" />}
        </IconButton>
      </Tooltip>

      <Typography
        sx={{
          width: { xs: 120, sm: 168 }, flexShrink: 0, fontSize: 14,
          fontWeight: unread ? 700 : 400, color: 'text.primary',
          overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
        }}
      >
        {thread.has_draft && label !== 'drafts' && (
          <Box component="span" sx={{ color: '#d93025', mr: 0.5 }}>Draf</Box>
        )}
        {senderSummary(thread.senders, thread.message_count, me)}
      </Typography>

      <Box sx={{ flex: 1, minWidth: 0, display: 'flex', alignItems: 'center', gap: 1 }}>
        {chips.map((chip) => (
          <Chip
            key={chip.id}
            label={chip.name}
            size="small"
            sx={{
              bgcolor: chip.color, color: '#fff', flexShrink: 0,
              '& .MuiChip-label': { px: 0.75 },
            }}
          />
        ))}
        <Typography
          component="span"
          sx={{
            fontSize: 14, fontWeight: unread ? 700 : 400,
            overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
          }}
        >
          {thread.last_message.subject || '(tanpa subjek)'}
          <Box component="span" sx={{ color: 'text.secondary', fontWeight: 400 }}>
            {thread.last_message.snippet ? ` — ${thread.last_message.snippet}` : ''}
          </Box>
        </Typography>
      </Box>

      {thread.has_attachment && (
        <AttachFileIcon fontSize="small" sx={{ color: 'text.secondary', transform: 'rotate(45deg)' }} />
      )}

      <Box sx={{ width: 96, flexShrink: 0, display: 'flex', justifyContent: 'flex-end', pr: 1 }}>
        <Typography
          className="row-date"
          variant="caption"
          sx={{ fontWeight: unread ? 700 : 400, color: 'text.secondary' }}
        >
          {listDate(thread.date)}
        </Typography>

        <Box className="row-actions" sx={{ display: 'none', alignItems: 'center' }}>
          <Tooltip title="Arsipkan">
            <IconButton size="small" onClick={stop(() => actions.archive(target))}>
              <ArchiveOutlinedIcon fontSize="small" />
            </IconButton>
          </Tooltip>
          <Tooltip title="Hapus">
            <IconButton size="small" onClick={stop(() => actions.trash(target))}>
              <DeleteOutlineIcon fontSize="small" />
            </IconButton>
          </Tooltip>
          <Tooltip title={unread ? 'Tandai sudah dibaca' : 'Tandai belum dibaca'}>
            <IconButton
              size="small"
              onClick={stop(() => actions.read(target, unread))}
            >
              {unread
                ? <DraftsOutlinedIcon fontSize="small" />
                : <MarkEmailUnreadOutlinedIcon fontSize="small" />}
            </IconButton>
          </Tooltip>
          <Tooltip title="Tunda (belum tersedia)">
            <span>
              <IconButton size="small" disabled><AccessTimeIcon fontSize="small" /></IconButton>
            </span>
          </Tooltip>
        </Box>
      </Box>
    </Box>
  );
}

export default memo(ThreadRow);
