import { useState } from 'react';
import {
  Box, Button, Collapse, Divider, IconButton, LinearProgress, List, ListItemButton,
  ListItemIcon, ListItemText, Tooltip, Typography,
} from '@mui/material';
import CreateIcon from '@mui/icons-material/Create';
import InboxIcon from '@mui/icons-material/Inbox';
import StarBorderIcon from '@mui/icons-material/StarBorder';
import AccessTimeIcon from '@mui/icons-material/AccessTime';
import SendIcon from '@mui/icons-material/Send';
import InsertDriveFileOutlinedIcon from '@mui/icons-material/InsertDriveFileOutlined';
import LabelImportantOutlinedIcon from '@mui/icons-material/LabelImportantOutlined';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import ReportGmailerrorredIcon from '@mui/icons-material/ReportGmailerrorred';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import PeopleOutlineIcon from '@mui/icons-material/PeopleOutline';
import LocalOfferOutlinedIcon from '@mui/icons-material/LocalOfferOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import LabelOutlinedIcon from '@mui/icons-material/LabelOutlined';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import AddIcon from '@mui/icons-material/Add';
import CloudOutlinedIcon from '@mui/icons-material/CloudOutlined';
import { useMail } from '../state/MailContext';
import { useAuth } from '../state/AuthContext';
import { formatBytes, quotaPercent } from '../utils/format';
import LabelDialog from './LabelDialog';

const ICONS = {
  inbox: InboxIcon,
  starred: StarBorderIcon,
  snoozed: AccessTimeIcon,
  sent: SendIcon,
  drafts: InsertDriveFileOutlinedIcon,
  important: LabelImportantOutlinedIcon,
  archive: ArchiveOutlinedIcon,
  spam: ReportGmailerrorredIcon,
  trash: DeleteOutlineIcon,
  social: PeopleOutlineIcon,
  promotions: LocalOfferOutlinedIcon,
  updates: InfoOutlinedIcon,
};

function LabelRow({ item, active, onClick, onEdit }) {
  const Icon = ICONS[item.slug] || LabelOutlinedIcon;
  const showBadge = item.slug === 'drafts' ? item.total : item.unread;
  const bold = item.unread > 0;

  return (
    <ListItemButton
      selected={active}
      onClick={onClick}
      sx={{
        pl: 3, pr: 1.5,
        '&.Mui-selected': { bgcolor: 'action.selected', '&:hover': { bgcolor: 'action.selected' } },
        '& .label-edit': { opacity: 0 },
        '&:hover .label-edit': { opacity: 1 },
      }}
    >
      <ListItemIcon sx={{ minWidth: 34, color: active ? 'text.primary' : 'inherit' }}>
        <Icon fontSize="small" sx={item.type === 'user' ? { color: item.color } : undefined} />
      </ListItemIcon>
      <ListItemText
        primary={item.name}
        primaryTypographyProps={{
          fontSize: 14,
          fontWeight: bold || active ? 700 : 400,
          noWrap: true,
        }}
      />
      {showBadge > 0 && (
        <Typography variant="caption" sx={{ fontWeight: bold ? 700 : 400, ml: 1 }}>
          {showBadge > 999 ? '999+' : showBadge}
        </Typography>
      )}
      {onEdit && (
        <IconButton
          className="label-edit"
          size="small"
          onClick={(event) => { event.stopPropagation(); onEdit(item); }}
          aria-label={`Edit label ${item.name}`}
        >
          <AddIcon sx={{ fontSize: 14, transform: 'rotate(45deg)' }} />
        </IconButton>
      )}
    </ListItemButton>
  );
}

export default function Sidebar({ collapsed }) {
  const { labels, label: activeLabel, openLabel, openCompose } = useMail();
  const { user } = useAuth();
  const [showMore, setShowMore] = useState(false);
  const [labelDialog, setLabelDialog] = useState(null);

  const primary = ['inbox', 'starred', 'snoozed', 'sent', 'drafts'];
  const secondary = ['important', 'archive', 'spam', 'trash'];
  const categories = labels.filter((item) => item.type === 'category');
  const userLabels = labels.filter((item) => item.type === 'user');
  const bySlug = (slug) => labels.find((item) => item.slug === slug);

  if (collapsed) {
    return (
      <Box sx={{ width: 72, flexShrink: 0, pt: 1, display: { xs: 'none', md: 'block' } }}>
        <Tooltip title="Tulis pesan" placement="right">
          <IconButton
            onClick={() => openCompose()}
            sx={{
              mx: 1.5, mb: 2, width: 48, height: 48, borderRadius: '16px',
              bgcolor: '#c2e7ff', color: '#001d35', '&:hover': { bgcolor: '#b0dcff' },
            }}
          >
            <CreateIcon />
          </IconButton>
        </Tooltip>
        {primary.map((slug) => {
          const item = bySlug(slug);
          if (!item) return null;
          const Icon = ICONS[slug] || LabelOutlinedIcon;
          return (
            <Tooltip key={slug} title={item.name} placement="right">
              <IconButton
                onClick={() => openLabel(slug)}
                sx={{
                  display: 'flex', mx: 'auto', mb: 0.5,
                  bgcolor: activeLabel === slug ? 'action.selected' : 'transparent',
                }}
              >
                <Icon fontSize="small" />
              </IconButton>
            </Tooltip>
          );
        })}
      </Box>
    );
  }

  return (
    <Box
      component="nav"
      sx={{
        width: 256, flexShrink: 0, pt: 1, pb: 2, overflowY: 'auto', overflowX: 'hidden',
        display: 'flex', flexDirection: 'column',
      }}
    >
      <Button
        onClick={() => openCompose()}
        startIcon={<CreateIcon />}
        sx={{
          alignSelf: 'flex-start', ml: 1.5, mb: 2, px: 3, height: 56, borderRadius: '16px',
          bgcolor: '#c2e7ff', color: '#001d35', fontSize: 14,
          boxShadow: '0 1px 3px rgba(60,64,67,.15)',
          '&:hover': { bgcolor: '#b0dcff', boxShadow: '0 2px 6px rgba(60,64,67,.25)' },
        }}
      >
        Tulis
      </Button>

      <List dense disablePadding sx={{ pr: 1 }}>
        {primary.map((slug) => {
          const item = bySlug(slug);
          return item ? (
            <LabelRow key={slug} item={item} active={activeLabel === slug} onClick={() => openLabel(slug)} />
          ) : null;
        })}

        <ListItemButton onClick={() => setShowMore((value) => !value)} sx={{ pl: 3 }}>
          <ListItemIcon sx={{ minWidth: 34 }}>
            {showMore ? <ExpandLessIcon fontSize="small" /> : <ExpandMoreIcon fontSize="small" />}
          </ListItemIcon>
          <ListItemText primary={showMore ? 'Ciutkan' : 'Selengkapnya'} primaryTypographyProps={{ fontSize: 14 }} />
        </ListItemButton>

        <Collapse in={showMore} unmountOnExit>
          {secondary.map((slug) => {
            const item = bySlug(slug);
            return item ? (
              <LabelRow key={slug} item={item} active={activeLabel === slug} onClick={() => openLabel(slug)} />
            ) : null;
          })}
        </Collapse>
      </List>

      {categories.length > 0 && (
        <>
          <Divider sx={{ my: 1, mr: 1 }} />
          <Typography variant="caption" sx={{ pl: 3, color: 'text.secondary', fontWeight: 500 }}>
            Kategori
          </Typography>
          <List dense disablePadding sx={{ pr: 1 }}>
            {categories.map((item) => (
              <LabelRow key={item.slug} item={item} active={activeLabel === item.slug} onClick={() => openLabel(item.slug)} />
            ))}
          </List>
        </>
      )}

      <Divider sx={{ my: 1, mr: 1 }} />
      <Box sx={{ display: 'flex', alignItems: 'center', pl: 3, pr: 1 }}>
        <Typography variant="caption" sx={{ flex: 1, color: 'text.secondary', fontWeight: 500 }}>
          Label
        </Typography>
        <Tooltip title="Buat label baru">
          <IconButton size="small" onClick={() => setLabelDialog({})}>
            <AddIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Box>
      <List dense disablePadding sx={{ pr: 1 }}>
        {userLabels.map((item) => (
          <LabelRow
            key={item.slug}
            item={item}
            active={activeLabel === item.slug}
            onClick={() => openLabel(item.slug)}
            onEdit={setLabelDialog}
          />
        ))}
      </List>

      <Box sx={{ mt: 'auto', px: 3, pt: 3 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
          <CloudOutlinedIcon fontSize="small" sx={{ color: 'text.secondary' }} />
          <Typography variant="caption" color="text.secondary">Penyimpanan</Typography>
        </Box>
        <LinearProgress
          variant="determinate"
          value={quotaPercent(user?.used_bytes, user?.quota_bytes)}
          sx={{ height: 6, borderRadius: 3, mb: 0.5 }}
        />
        <Typography variant="caption" color="text.secondary">
          {formatBytes(user?.used_bytes || 0)} dari {formatBytes(user?.quota_bytes || 0)}
        </Typography>
      </Box>

      <LabelDialog
        open={Boolean(labelDialog)}
        label={labelDialog?.id ? labelDialog : null}
        onClose={() => setLabelDialog(null)}
      />
    </Box>
  );
}
