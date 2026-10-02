import React from 'react'
import { TextInput, Select } from './FormSection'

interface SearchFilterBarProps {
  value: string
  onSearchChange: (value: string) => void
  placeholder?: string
  filters?: { key: string; label: string; options: { value: string; label: string }[] }[]
  filterValues?: Record<string, string>
  onFilterChange?: (key: string, value: string) => void
}

export const SearchFilterBar: React.FC<SearchFilterBarProps> = ({
  value,
  onSearchChange,
  placeholder = 'Search…',
  filters = [],
  filterValues = {},
  onFilterChange,
}) => (
  <div className="mb-4 flex flex-wrap items-center gap-3">
    <div className="min-w-[16rem] flex-1">
      <TextInput
        type="search"
        value={value}
        placeholder={placeholder}
        aria-label={placeholder}
        onChange={(e) => onSearchChange(e.target.value)}
      />
    </div>
    {filters.map((filter) => (
      <Select
        key={filter.key}
        aria-label={filter.label}
        value={filterValues[filter.key] ?? ''}
        onChange={(e) => onFilterChange?.(filter.key, e.target.value)}
        className="w-44"
      >
        <option value="">{filter.label}: All</option>
        {filter.options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </Select>
    ))}
  </div>
)