import React from 'react'
import { Modal } from './Modal'
import { DangerButton, SecondaryButton } from './PageHeader'

interface ConfirmDialogProps {
  open: boolean
  title: string
  message: React.ReactNode
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
  loading?: boolean
  onConfirm: () => void
  onCancel: () => void
}

export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  open,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  danger = false,
  loading = false,
  onConfirm,
  onCancel,
}) => {
  const ConfirmButton = danger ? DangerButton : SecondaryButton
  return (
    <Modal
      open={open}
      title={title}
      onClose={onCancel}
      size="sm"
      footer={
        <>
          <SecondaryButton onClick={onCancel} disabled={loading}>
            {cancelLabel}
          </SecondaryButton>
          <ConfirmButton onClick={onConfirm} disabled={loading}>
            {loading ? 'Please wait…' : confirmLabel}
          </ConfirmButton>
        </>
      }
    >
      <div className="text-sm text-clinical-slate-600">{message}</div>
    </Modal>
  )
}