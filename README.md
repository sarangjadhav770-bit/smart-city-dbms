# smart-city-dbms

## Kaggle competition download

Install KaggleHub and configure Kaggle API credentials after signing in to Kaggle and accepting the competition rules:

```powershell
py -m pip install kagglehub
py download_kaggle_competition.py
```

The script prints the local Kaggle cache path. It downloads files only; it does not import data into CivicPulse. Review the competition rules and remove or anonymize personal identifiers before use. Do not commit competition data, Kaggle credentials, or personal data to this repository.
