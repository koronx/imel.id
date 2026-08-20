import { Box, Tab, Tabs } from '@mui/material';
import InboxIcon from '@mui/icons-material/Inbox';
import PeopleOutlineIcon from '@mui/icons-material/PeopleOutline';
import LocalOfferOutlinedIcon from '@mui/icons-material/LocalOfferOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import ListToolbar from '../components/ListToolbar';
import ThreadList from '../components/ThreadList';
import ThreadView from '../components/ThreadView';
import { useMail } from '../state/MailContext';

const CATEGORY_TABS = [
  { slug: 'inbox', label: 'Utama', icon: <InboxIcon fontSize="small" /> },
  { slug: 'social', label: 'Sosial', icon: <PeopleOutlineIcon fontSize="small" /> },
  { slug: 'promotions', label: 'Promosi', icon: <LocalOfferOutlinedIcon fontSize="small" /> },
  { slug: 'updates', label: 'Notifikasi', icon: <InfoOutlinedIcon fontSize="small" /> },
];

export default function MailPage() {
  const { threadKey, label, openLabel } = useMail();

  if (threadKey) return <ThreadView />;

  const showTabs = CATEGORY_TABS.some((tab) => tab.slug === label);

  return (
    <>
      <ListToolbar />
      {showTabs && (
        <Box sx={{ borderBottom: '1px solid', borderColor: 'divider', flexShrink: 0 }}>
          <Tabs
            value={label}
            onChange={(event, next) => openLabel(next)}
            variant="scrollable"
            scrollButtons="auto"
            sx={{ minHeight: 48, '& .MuiTab-root': { minHeight: 48, textTransform: 'none', fontSize: 14 } }}
          >
            {CATEGORY_TABS.map((tab) => (
              <Tab key={tab.slug} value={tab.slug} icon={tab.icon} iconPosition="start" label={tab.label} />
            ))}
          </Tabs>
        </Box>
      )}
      <ThreadList />
    </>
  );
}
