import { useState, useEffect } from 'react'
import api from '../services/api'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '../components/ui/StatusBadge'
import { EmptyState, LoadingState } from '../components/ui/States'

interface Notification {
  id: number
  message: string
  channel: 'email' | 'sms' | 'whatsapp'
  read: boolean
  created_at: string
  action_url?: string
}

interface ChannelStatus {
  channel: 'email' | 'sms' | 'whatsapp'
  enabled: boolean
  last_sent?: string
}

export const NotificationsView = ({ onToggleChannel }: { onToggleChannel?: (channel: 'email' | 'sms' | 'whatsapp', enabled: boolean) => void } = {}) => {
  const { t } = useTranslation()
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [channelStatuses, setChannelStatuses] = useState<ChannelStatus[]>([
    { channel: 'email', enabled: true },
    { channel: 'sms', enabled: true },
    { channel: 'whatsapp', enabled: true }
  ])
  const [loading, setLoading] = useState(true)
  const [unreadCount, setUnreadCount] = useState(0)

  useEffect(() => {
    const loadData = async () => {
      setLoading(true)
      try {
        const [notifResp, statusResp] = await Promise.all([
          api.get('/notifications'),
          api.get('/notifications/channels')
        ])

        setNotifications(notifResp.data.data || [])
        setChannelStatuses(statusResp.data.data || [
          { channel: 'email', enabled: true },
          { channel: 'sms', enabled: true },
          { channel: 'whatsapp', enabled: true }
        ])

        const unread = (notifResp.data.data || []).filter((n: Notification) => !n.read).length
        setUnreadCount(unread)
      } catch (error) {
        console.error('Failed to load notifications:', error)
      } finally {
        setLoading(false)
      }
    }

    loadData()

    const interval = setInterval(() => {
      loadData()
    }, 30000)

    return () => clearInterval(interval)
  }, [])

  const handleMarkRead = async (id: number) => {
    try {
      await api.put(`/notifications/${id}/read`)
      setNotifications((notifs) =>
        notifs.map((n) => (n.id === id ? { ...n, read: true } : n))
      )
      setUnreadCount((count) => Math.max(0, count - 1))
    } catch (error) {
      console.error('Failed to mark notification as read:', error)
    }
  }

  const handleToggleChannel = async (
    channel: 'email' | 'sms' | 'whatsapp',
    enabled: boolean
  ) => {
    try {
      await api.put(`/notifications/channels/${channel}`, { enabled })
      setChannelStatuses((statuses) =>
        statuses.map((s) => (s.channel === channel ? { ...s, enabled } : s))
      )
      onToggleChannel?.(channel, enabled)
    } catch (error) {
      console.error('Failed to update channel preference:', error)
    }
  }

  return (
    <div className="mx-auto max-w-4xl">
      <h2 className="font-semibold text-clinical-slate-900 mb-6">{t('notifications.title')}</h2>

      <div className="grid grid-cols-1 gap-6">
        {/* Unread Notifications List */}
        <div className="bg-white rounded-lg p-6 shadow-sm">
          <h3 className="font-medium text-clinical-slate-900 mb-4">
            {unreadCount > 0 ? (
              <span className="text-clinical-indigo-600">
                {t('notifications.unread_count', { count: unreadCount })}
              </span>
            ) : (
              t('notifications.no_unread')
            )}
          </h3>
          {loading ? (
            <div className="h-40 animate-pulse bg-clinical-slate-200 rounded" />
          ) : notifications.length === 0 ? (
            <p className="text-clinical-slate-400">{t('notifications.no_notifications')}</p>
          ) : (
            <div className="space-y-3 max-h-80 overflow-y-auto">
              {notifications.map((notif) => (
                <div
                  key={notif.id}
                  className={`flex items-start gap-3 p-3 rounded bg-clinical-slate-50 ${
                    !notif.read ? 'font-medium text-clinical-slate-900' : 'text-clinical-slate-500'}
                  `}
                >
                  <span
                    className={`w-2 h-2 rounded-full ${
                      !notif.read ? 'bg-clinical-indigo-600' : 'bg-clinical-slate-400'
                    }`}
                  />
                  <div className="flex-1 text-sm">
                    <div className="font-medium text-clinical-slate-600">{notif.message}</div>
                    <div className="text-xs text-clinical-slate-400">
                      {new Date(notif.created_at).toLocaleTimeString()} • {t(`notifications.channel.${notif.channel}`)}
                    </div>
                  </div>
                  {notif.action_url ? (
                    <button
                      className="text-clinical-indigo-500 text-sm hover:underline"
                      onClick={() => window.open(notif.action_url, '_blank')}
                    >
                      {t('notifications.view')}
                    </button>
                  ) : !notif.read ? (
                    <button
                      className="text-clinical-indigo-500 text-sm hover:underline"
                      onClick={() => handleMarkRead(notif.id)}
                    >
                      {t('notifications.mark_read')}
                    </button>
                  ) : null}
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Channel Preferences */}
        <div className="bg-white rounded-lg p-6 shadow-sm">
          <h3 className="font-medium text-clinical-slate-900 mb-4">{t('notifications.channel_preferences')}</h3>
          {loading ? (
            <p className="text-clinical-slate-400">{t('notifications.loading')}</p>
          ) : channelStatuses.length === 0 ? (
            <p className="text-clinical-slate-400">{t('notifications.no_channel_data')}</p>
          ) : (
            <div className="space-y-4">
              {channelStatuses.map((channel) => (
                <div key={channel.channel} className="flex items-center justify-between">
                  <span className="text-clinical-slate-600">
                    {channel.channel === 'email' ? t('notifications.email') : channel.channel === 'sms' ? t('notifications.sms') : t('notifications.whatsapp')}
                  </span>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={channel.enabled}
                      onChange={(e) =>
                        handleToggleChannel(channel.channel, e.target.checked)
                      }
                      className="rounded border-clinical-slate-300 focus:outline-none focus:ring-2 focus:ring-clinical-indigo-200"
                    />
                    <span className="text-clinical-slate-400 text-xs">{t('notifications.disable')}</span>
                  </label>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}