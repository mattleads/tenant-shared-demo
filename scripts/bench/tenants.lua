-- wrk script: every request names the next of three tenants, so each PHP thread
-- or process sees a tenant switch on most requests.
local tenants = {
  "11111111-1111-4111-8111-111111111111",
  "22222222-2222-4222-8222-222222222222",
  "33333333-3333-4333-8333-333333333333",
}
local i = 0
request = function()
  i = i + 1
  return wrk.format("GET", "/tenant_" .. tenants[(i % #tenants) + 1] .. "/notes")
end
