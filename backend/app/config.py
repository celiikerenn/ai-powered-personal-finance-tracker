"""
Uygulama ayarları - MySQL bağlantı bilgileri (XAMPP varsayılanları).
"""
from urllib.parse import quote_plus

from pydantic import AliasChoices, Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Ortam değişkenleri veya .env üzerinden okunabilir."""
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",  # .env'deki fazla değişkenler hata vermesin
    )
    # MySQL (XAMPP varsayılan) - .env'de MYSQL_DB veya MYSQL_DATABASE kullanılabilir
    MYSQL_HOST: str = "127.0.0.1"
    MYSQL_PORT: int = 3306
    MYSQL_USER: str = "root"
    MYSQL_PASSWORD: str = ""
    MYSQL_DATABASE: str = Field(
        default="finance_tracker",
        validation_alias=AliasChoices("MYSQL_DB", "MYSQL_DATABASE"),
    )

    @property
    def database_url(self) -> str:
        """SQLAlchemy için MySQL connection string. Şifredeki özel karakterler URL-encode edilir."""
        password_encoded = quote_plus(self.MYSQL_PASSWORD)
        return (
            f"mysql+pymysql://{self.MYSQL_USER}:{password_encoded}"
            f"@{self.MYSQL_HOST}:{self.MYSQL_PORT}/{self.MYSQL_DATABASE}"
        )

settings = Settings()
