import assert from 'node:assert/strict'
import test from 'node:test'
import { DEFAULT_API_BASE_URL, resolveApiBaseUrl } from './apiBaseUrl.js'

test('a bare origin gains the /api prefix', () => {
  assert.equal(resolveApiBaseUrl('http://127.0.0.1:8000'), 'http://127.0.0.1:8000/api')
  assert.equal(resolveApiBaseUrl('http://localhost:8000'), 'http://localhost:8000/api')
  assert.equal(resolveApiBaseUrl('https://api.example.com'), 'https://api.example.com/api')
})

test('trailing slashes are removed before the /api suffix is applied', () => {
  assert.equal(resolveApiBaseUrl('http://127.0.0.1:8000/'), 'http://127.0.0.1:8000/api')
  assert.equal(resolveApiBaseUrl('http://127.0.0.1:8000///'), 'http://127.0.0.1:8000/api')
})

test('a base URL that already ends with /api is left intact', () => {
  assert.equal(resolveApiBaseUrl('http://127.0.0.1:8000/api'), 'http://127.0.0.1:8000/api')
  assert.equal(resolveApiBaseUrl('http://127.0.0.1:8000/api/'), 'http://127.0.0.1:8000/api')
  assert.equal(resolveApiBaseUrl('https://api.example.com/API'), 'https://api.example.com/API')
})

test('missing configuration falls back to the local API base URL', () => {
  assert.equal(resolveApiBaseUrl(), DEFAULT_API_BASE_URL)
  assert.equal(resolveApiBaseUrl(''), DEFAULT_API_BASE_URL)
  assert.equal(resolveApiBaseUrl('   '), DEFAULT_API_BASE_URL)
  assert.equal(DEFAULT_API_BASE_URL, 'http://127.0.0.1:8000/api')
})

test('custom API paths are preserved instead of double-prefixed', () => {
  assert.equal(resolveApiBaseUrl('https://gateway.example.com/eventpass/api'), 'https://gateway.example.com/eventpass/api')
  assert.equal(resolveApiBaseUrl('https://gateway.example.com/eventpass'), 'https://gateway.example.com/eventpass')
})
