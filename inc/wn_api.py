"""Wiener Netze Smart Meter API client.

Auth flow: OAuth2 client_credentials against the log.wien Keycloak realm,
then API calls to the WSTW gateway with both the Bearer token AND an
x-Gateway-APIKey header (the gateway requires both).

The OpenAPI spec lists a misleading token URL — the Postman collection is
authoritative: tokens come from log.wien, not the api.wstw.at gateway.

All endpoints and fetch parameters live in the `[wn_api]` config section;
sensible defaults are applied here so the config can stay minimal.
"""

import time
from datetime import datetime

import requests

DEFAULT_TOKEN_URL = "https://log.wien/auth/realms/logwien/protocol/openid-connect/token"
DEFAULT_API_BASE  = "https://api.wstw.at/gateway/WN_SMART_METER_API/1.0"
DEFAULT_WERTETYP  = "QUARTER_HOUR"
DEFAULT_TIMEOUT   = 60

# Cached access token: {"token": str, "expires_at": float (epoch s)}
_token_cache: dict = {}


def _section(cfg):
    return cfg["wn_api"]


def _timeout(cfg) -> int:
    return int(_section(cfg).get("request_timeout", DEFAULT_TIMEOUT))


def get_access_token(cfg) -> str:
    """Return a cached access token, refreshing if expired or near-expiry."""
    now = time.time()
    cached = _token_cache.get("token")
    if cached and _token_cache.get("expires_at", 0) > now + 30:
        return cached

    section = _section(cfg)
    resp = requests.post(
        section.get("token_url", DEFAULT_TOKEN_URL),
        data={
            "grant_type":    "client_credentials",
            "client_id":     section["client_id"],
            "client_secret": section["client_secret"],
        },
        timeout=_timeout(cfg),
    )
    resp.raise_for_status()
    body = resp.json()
    _token_cache["token"]      = body["access_token"]
    _token_cache["expires_at"] = now + int(body.get("expires_in", 60))
    return body["access_token"]


def _headers(cfg) -> dict:
    return {
        "Authorization":    f"Bearer {get_access_token(cfg)}",
        "x-Gateway-APIKey": _section(cfg)["api_key"],
        "Accept":           "application/json",
    }


def _api_base(cfg) -> str:
    return _section(cfg).get("api_base", DEFAULT_API_BASE).rstrip("/")


def list_zaehlpunkte(cfg) -> list[dict]:
    """Discovery call — returns the Zählpunkte the credentials have access to."""
    resp = requests.get(
        f"{_api_base(cfg)}/zaehlpunkte",
        headers=_headers(cfg),
        timeout=_timeout(cfg),
    )
    resp.raise_for_status()
    return resp.json()


def fetch_messwerte(
    cfg,
    date_from: datetime,
    date_to:   datetime,
    wertetyp: str | None = None,
) -> list[dict]:
    """Fetch quarter-hour consumption for the configured Zählpunkt.

    Returns rows shaped like the CSV importer's output:
        [{"ts": "YYYY-MM-DDTHH:MM:SS", "consumed_kwh": float}, …]

    The API reports `messwert` as an int64 whose unit depends on `einheit`
    (typically "KWH" or "WH"). We normalise to kWh here so the caller can
    feed the rows straight into `_compute_and_upsert_consumption()`.
    """
    section = _section(cfg)
    zp      = section["zaehlpunkt"]
    wtype   = wertetyp or section.get("wertetyp", DEFAULT_WERTETYP)
    params = {
        "datumVon": date_from.strftime("%Y-%m-%dT%H:%M:%S"),
        "datumBis": date_to.strftime("%Y-%m-%dT%H:%M:%S"),
        "wertetyp": wtype,
    }
    resp = requests.get(
        f"{_api_base(cfg)}/zaehlpunkte/{zp}/messwerte",
        headers=_headers(cfg),
        params=params,
        timeout=_timeout(cfg),
    )
    resp.raise_for_status()
    payload = resp.json()

    rows: list[dict] = []
    for werk in payload.get("zaehlwerke", []):
        einheit = (werk.get("einheit") or "KWH").upper()
        # Raw int64 → kWh. WH divides by 1000; KWH is already kWh.
        scale = 0.001 if einheit == "WH" else 1.0
        for mw in werk.get("messwerte", []):
            ts_raw = mw.get("zeitVon")
            val    = mw.get("messwert")
            if ts_raw is None or val is None:
                continue
            # Strip timezone suffix if present — the DB stores naive local time.
            ts = ts_raw.split("+")[0].split("Z")[0]
            if "." in ts:
                ts = ts.split(".")[0]
            rows.append({"ts": ts, "consumed_kwh": float(val) * scale})
    return rows
